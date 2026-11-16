<?php

namespace Base\Agenda\Repository;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Scope\TrashScope;
use Base\Database\UnitOfWorks;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Contract\Backend;
use Base\Contract\UnitOfWork;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @method Venue|null find($id, $lockMode = null, $lockVersion = null)
 * @method Venue|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Venue[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class VenueRepository extends ServiceEntityRepository
{
    /**
     * The scope a merge lifts to reach every date of the house: the trash's (Doctrine's SQL filter "trash_filter").
     * The query suspended every enabled filter; Event (a Thread) is under the trash's alone - the vault's needs its
     * parameters to be enabled again, which the Backend contract does not give back.
     */
    private const SCOPES = [TrashScope::NAME];

    public function __construct(Finders $finders, private readonly UnitOfWork $unitOfWork)
    {
        parent::__construct($finders, Venue::class);
    }

    /**
     * The house of that name in that town (whatever the case), else the one
     * that spelling was merged into, so an import does not open it twice.
     */
    public function findOneByNameAndCity(string $name, ?string $city = null): ?Venue
    {
        $criteria = [Group::of(Parser::SEPARATOR_AND,
            Criterion::of('name', mb_strtolower(trim($name)), Parser::OPTION_INSENSITIVE),
            null !== $city && '' !== trim($city)
                ? Criterion::of('city', mb_strtolower(trim($city)), Parser::OPTION_INSENSITIVE)
                : Criterion::of('city', null, Parser::OPTION_NULL),
        )];

        return $this->findOneBy($criteria, ['id' => 'ASC']) ?? $this->findOneByAlias($name, $city);
    }

    /** The house whose full address (Venue::getFullAddress(), a calendar's LOCATION as the site writes it) is that line. */
    public function findOneByFullAddress(string $location): ?Venue
    {
        // Compared as read: the case, the spaces (a calendar trims what a form kept) aside.
        $line = fn (string $text): string => preg_replace(['/\s+/u', '/\s*,\s*/u'], [' ', ', '], mb_strtolower(trim($text)));
        $location = $line($location);
        foreach ($this->findBy([], ['id' => 'ASC']) as $venue) {
            if ($line($venue->getFullAddress()) === $location) {
                return $venue;
            }
        }

        return null;
    }

    /** The house a spelling was merged into ("Pärmu Concert Hall" into "Pärnu Concert Hall"). */
    public function findOneByAlias(string $name, ?string $city = null): ?Venue
    {
        // A site has a few dozen houses, a few of them merged: read in PHP, whatever the database's JSON. All of them:
        // the language compiles a criterion on a JSON field as JSON_CONTAINS, never as IS NOT NULL.
        foreach ($this->findBy([], ['id' => 'ASC']) as $venue) {
            if ([] !== $venue->getAliases() && $venue->isKnownAs($name, $city)) {
                return $venue;
            }
        }

        return null;
    }

    /**
     * Two houses that are one: the dates of $from are moved to $into, what
     * $into leaves empty is taken from $from (its photo copied, the upload
     * of $from going with it), the spellings of $from become aliases of
     * $into, and $from is deleted.
     *
     * @return int the dates moved
     */
    public function merge(Venue $from, Venue $into): int
    {
        if ($from === $into || $from->getId() === $into->getId()) {
            throw new \InvalidArgumentException('A house cannot be merged into itself.');
        }
        foreach (['Hall', 'Address', 'Postcode', 'Country', 'MapUrl', 'Website', 'Latitude', 'Longitude'] as $field) {
            if (null === $into->{'get'.$field}() && null !== $from->{'get'.$field}()) {
                $into->{'set'.$field}($from->{'get'.$field}());
            }
        }
        if (!$into->hasPhoto() && $from->hasPhoto() && ($file = $from->getPhotoFile()) instanceof File && $file->isFile()) {
            $copy = tempnam(sys_get_temp_dir(), 'venue');
            $extension = $file->guessExtension() ?? 'jpg';
            if (false !== $copy && rename($copy, $copy .= '.'.$extension) && copy($file->getPathname(), $copy)) {
                $into->setPhoto(new File($copy));
                $into->setPhotoCredit($into->getPhotoCredit() ?? $from->getPhotoCredit());
            }
        }
        $into->setAliases([...$into->getAliases(), Venue::key($from->getName(), $from->getCity()), ...$from->getAliases()]);

        // Every date of the house, the drafts and the trashed ones too: deleted, it would leave them nowhere.
        // The persistence's scopes lifted meanwhile.
        $backend = Backends::of($this->unitOfWork, Backend::class);
        $suspended = array_values(array_filter(self::SCOPES, fn (string $scope) => $backend->isScopeEnabled($scope)));
        array_walk($suspended, fn (string $scope) => $backend->disableScope($scope));
        try {
            $events = UnitOfWorks::repositories($this->unitOfWork)->of(Event::class)->findBy(['venue' => Key::of($from)]);
        } finally {
            array_walk($suspended, fn (string $scope) => $backend->isScopeEnabled($scope) || $backend->enableScope($scope));
        }
        foreach ($events as $event) {
            $event->setVenue($into);
        }
        $moved = \count($events);
        $this->unitOfWork->remove($from);
        $this->unitOfWork->flush();

        return $moved;
    }

    /** @return list<Venue> by name, for a select */
    public function findAllByName(): array
    {
        return $this->findBy([], ['name' => 'ASC', 'city' => 'ASC']);
    }
}
