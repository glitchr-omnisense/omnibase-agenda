<?php

namespace Base\Agenda\Controller\Admin\Crud;

use Base\Admin\Attribute\OpenToAdmins;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Agenda\Entity\Venue;
use Base\Field\CountryField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;

/**
 * The houses the dates are played in: typed once, chosen on every date.
 * The importer opens one for each new LOCATION it reads - with the name
 * and the town only: the address, the map and the site are completed here.
 */
#[OpenToAdmins]
class VenueCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Venue::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-landmark';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('city')->add('country');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@agenda.admin.venue.name')->setColumns(6);
        yield TextField::new('hall', '@agenda.admin.venue.hall')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('address', '@agenda.admin.venue.address')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('postcode', '@agenda.admin.venue.postcode')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('city', '@agenda.admin.venue.city')->setRequired(false)->setColumns(4);
        yield CountryField::new('country', '@agenda.admin.venue.country')->setRequired(false)->setColumns(4);
        yield TextField::new('website', '@agenda.admin.venue.website')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('mapUrl', '@agenda.admin.venue.map_url')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('@agenda.admin.venue.map_url_help');
        yield NumberField::new('latitude', '@agenda.admin.venue.latitude')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield NumberField::new('longitude', '@agenda.admin.venue.longitude')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('slug', '@agenda.admin.venue.slug')->onlyOnDetail();
    }
}
