<?php

namespace Base\Agenda\EntityDispatcher;

use Base\Agenda\Entity\Venue;
use Base\Database\UnitOfWorks;
use Base\EntityDispatcher\AbstractEventDispatcher;
use Base\Contract\Lifecycle;

/**
 * Gives a house its slug when it is first saved, whichever persistence keeps it: Doctrine calls it as its prePersist
 * listener (the core's discovery tags every entity dispatcher), Eloquent through lifecycle(). It notes no event.
 *
 * The slug is the name and the city's (Venue::slugify()), "-2" when another house has it - one already saved, or one
 * waiting to be with this one (a calendar read opens many at once: "Bogota" and "Bogotá" make one slug). The ones
 * waiting are those this dispatcher saw persisted that the unit of work still holds as new: what the entity's own
 * Doctrine callback read from the unit of work's scheduled insertions.
 */
class VenueEventDispatcher extends AbstractEventDispatcher
{
    /** @var \WeakMap<Venue, true>|null the houses seen persisted, the slug they had or were given */
    private ?\WeakMap $persisted = null;

    public function supports(mixed $subject): bool
    {
        return $subject instanceof Venue;
    }

    public function persisting(Lifecycle\PrePersist $event): void
    {
        /** @var Venue $venue */
        $venue = $event->entity;
        $this->persisted ??= new \WeakMap();
        if (!$venue->getSlug()) {
            $venue->setSlug($this->slugFor($venue, $event));
        }
        $this->persisted[$venue] = true;
    }

    private function slugFor(Venue $venue, Lifecycle\PrePersist $event): string
    {
        $unitOfWork = $event->unitOfWork;
        $waiting = [];
        foreach ($this->persisted as $other => $_) {
            if ($other !== $venue && $other->getSlug() && $unitOfWork->contains($other) && $unitOfWork->isNew($other)) {
                $waiting[$other->getSlug()] = true;
            }
        }

        $repository = UnitOfWorks::repositories($unitOfWork)->of(Venue::class);
        $base = Venue::slugify($venue->getName(), $venue->getCity());
        $slug = $base;
        for ($i = 2; isset($waiting[$slug]) || $repository->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function reset(): void
    {
        parent::reset();
        $this->persisted = null;
    }
}
