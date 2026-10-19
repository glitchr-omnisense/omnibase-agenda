<?php

namespace Base\Agenda\Tests\Fixtures;

use Base\Agenda\Entity\Venue;

/** A Venue whose photo is an address given here: Uploader asks the kernel where an upload is, a unit test has none. */
class TestVenue extends Venue
{
    public ?string $testPhotoUrl = null;

    public function __construct(?string $name = null, ?string $city = null, ?string $slug = null)
    {
        parent::__construct($name, $city);
        $this->slug = $slug;
    }

    public function hasPhoto(): bool { return null !== $this->testPhotoUrl; }
    public function getPhotoUrl(): ?string { return $this->testPhotoUrl; }
}
