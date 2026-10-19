<?php

namespace Base\Agenda\Tests\Entity;

use Base\Agenda\Entity\Venue;
use PHPUnit\Framework\TestCase;

final class VenueTest extends TestCase
{
    public function testAHouseIsKnownByItsSpellingsWhateverTheCaseAndTheAccents(): void
    {
        $parnu = new Venue('Pärnu Concert Hall', 'Pärnu');

        $this->assertSame('parnu-concert-hall|parnu', Venue::key('Pärnu Concert Hall', 'Pärnu'));
        $this->assertSame('kiel-wunderino-arena|', Venue::key('Kiel Wunderino Arena', '  '));
        $this->assertTrue($parnu->isKnownAs('PÄRNU concert hall', 'parnu'));
        $this->assertFalse($parnu->isKnownAs('Pärmu Concert Hall', 'Pärnu'), 'a typo is another house until merged');

        // Merged: the spelling that went is kept, once.
        $parnu->setAliases([Venue::key('Pärmu Concert Hall', 'Pärnu'), Venue::key('Pärmu Concert Hall', 'Pärnu'), '']);
        $this->assertSame(['parmu-concert-hall|parnu'], $parnu->getAliases());
        $this->assertTrue($parnu->isKnownAs('Pärmu Concert Hall', 'Pärnu'));
        $this->assertSame([], $parnu->setAliases([])->getAliases(), 'none left');
    }

    public function testAnUploadsAddressOnTheSite(): void
    {
        $this->assertSame('/uploads/_/venue/_photo/0b2f', Venue::publicPath('/srv/app/public/uploads/_/venue/_photo/0b2f'));
        $this->assertSame('https://cdn.example/hall.jpg', Venue::publicPath('https://cdn.example/hall.jpg'));
        $this->assertNull(Venue::publicPath(''));
        $this->assertNull(Venue::publicPath(null));
    }
}
