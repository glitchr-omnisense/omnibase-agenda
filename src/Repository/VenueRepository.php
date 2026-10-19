<?php

namespace Base\Agenda\Repository;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\File\File;

/** @extends ServiceEntityRepository<Venue> */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /**
     * The house of that name in that town (whatever the case), else the one
     * that spelling was merged into, so an import does not open it twice.
     */
    public function findOneByNameAndCity(string $name, ?string $city = null): ?Venue
    {
        $query = $this->createQueryBuilder('v')
            ->andWhere('LOWER(v.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->orderBy('v.id', 'ASC')
            ->setMaxResults(1);
        $query = null !== $city && '' !== trim($city)
            ? $query->andWhere('LOWER(v.city) = :city')->setParameter('city', mb_strtolower(trim($city)))
            : $query->andWhere('v.city IS NULL');

        return $query->getQuery()->getOneOrNullResult() ?? $this->findOneByAlias($name, $city);
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
        // A site has a few dozen houses, a few of them merged: read in PHP, whatever the database's JSON.
        foreach ($this->createQueryBuilder('v')->andWhere('v.aliases IS NOT NULL')->orderBy('v.id', 'ASC')->getQuery()->getResult() as $venue) {
            if ($venue->isKnownAs($name, $city)) {
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
        $manager = $this->getEntityManager();

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
        $filters = $manager->getFilters();
        $suspended = array_keys($filters->getEnabledFilters());
        array_walk($suspended, fn (string $name) => $filters->suspend($name));
        try {
            $events = $manager->getRepository(Event::class)->findBy(['venue' => $from]);
        } finally {
            array_walk($suspended, fn (string $name) => $filters->restore($name));
        }
        foreach ($events as $event) {
            $event->setVenue($into);
        }
        $moved = \count($events);
        $manager->remove($from);
        $manager->flush();

        return $moved;
    }

    /** @return list<Venue> by name, for a select */
    public function findAllByName(): array
    {
        return $this->findBy([], ['name' => 'ASC', 'city' => 'ASC']);
    }
}
