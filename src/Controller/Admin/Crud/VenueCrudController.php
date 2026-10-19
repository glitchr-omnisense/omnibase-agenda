<?php

namespace Base\Agenda\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Attribute\OpenToAdmins;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Base\Agenda\Repository\VenueRepository;
use Base\Field\ArrayField;
use Base\Field\CountryField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The houses the dates are played in: typed once, chosen on every date.
 * The importer opens one for each new LOCATION it reads - with the name
 * and the town only: the address, the map, the site and the photo (shown
 * on every date there that has no picture of its own) are completed here.
 * Two houses that are one ("Pärnu Concert Hall", "Pärmu Concert Hall")
 * are merged: "Merge into…" moves the dates of one into the other, which
 * keeps the spelling that went, for the calendars that still write it.
 */
#[OpenToAdmins(actions: ['merge'])]
class VenueCrudController extends AbstractCrudController
{
    private TranslatorInterface $translator;

    #[Required]
    public function setAgendaTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

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
        yield ImageField::new('photo', '@agenda.admin.venue.photo')->setRequired(false)->setColumns(6)->setHelp('@agenda.admin.venue.photo_help');
        yield TextField::new('photoCredit', '@agenda.admin.venue.photo_credit')->setRequired(false)->setColumns(6)->hideOnIndex()->setHelp('@agenda.admin.venue.photo_credit_help');
        yield ArrayField::new('aliases', '@agenda.admin.venue.aliases')->onlyOnDetail();
        yield TextField::new('slug', '@agenda.admin.venue.slug')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL, Actions::PAGE_EDIT] as $page) {
            $actions->add($page, Action::new('merge', '@agenda.admin.venue.action.merge', 'fa-solid fa-code-merge')->linkToCrudAction('merge'));
        }

        return $actions;
    }

    /**
     * The house merged into another: the page lists the others, the likely
     * same first (the same town, a name a letter or two apart), with their
     * dates; posted, the dates move and this house goes.
     */
    #[AdminAction('/{entityId}/merge', methods: ['GET', 'POST'])]
    public function merge(Request $request, string $entityId): Response
    {
        /** @var Venue $venue */
        $venue = $this->findEntity($entityId);
        /** @var VenueRepository $venues */
        $venues = $this->entityManager->getRepository(Venue::class);

        if ($request->isMethod('POST')) {
            $into = $venues->find($request->request->getInt('into'));
            if (!$into instanceof Venue || $into->getId() === $venue->getId()) {
                $this->addFlash('danger', '@agenda.admin.venue.merge.choose');

                return $this->redirect($this->adminUrlGenerator->setController(static::class)->setAction('merge')->setEntityId($venue->getId())->generateUrl());
            }
            $name = $venue->getLabel();
            $moved = $venues->merge($venue, $into);
            $this->addFlash('success', $this->translator->trans('admin.venue.flash.merged', ['from' => $name, 'into' => $into->getLabel(), 'count' => $moved], 'agenda'));

            return $this->redirect($this->adminUrlGenerator->setController(static::class)->setAction(Action::DETAIL)->setEntityId($into->getId())->generateUrl());
        }

        $counts = [];
        foreach ($this->entityManager->createQuery('SELECT IDENTITY(e.venue) AS venue, COUNT(e.id) AS dates FROM '.Event::class.' e WHERE e.venue IS NOT NULL GROUP BY e.venue')->getArrayResult() as $row) {
            $counts[(int) $row['venue']] = (int) $row['dates'];
        }
        $others = [];
        foreach ($venues->findAllByName() as $other) {
            if ($other->getId() !== $venue->getId()) {
                $others[] = ['venue' => $other, 'dates' => $counts[$other->getId()] ?? 0, 'likeness' => self::likeness($venue, $other)];
            }
        }
        usort($others, fn (array $a, array $b) => [$b['likeness'] >= 80, $b['likeness']] <=> [$a['likeness'] >= 80, $a['likeness']] ?: strcasecmp((string) $a['venue']->getName(), (string) $b['venue']->getName()));

        return $this->renderCrud('@Agenda/admin/venue_merge.html.twig', [
            'venue' => $venue,
            'dates' => $counts[$venue->getId()] ?? 0,
            'others' => $others,
        ]);
    }

    /** How alike two houses are, 0 to 100: their names compared (cases and accents aside), the same town counting. */
    private static function likeness(Venue $a, Venue $b): int
    {
        similar_text(Venue::slugify($a->getName(), null), Venue::slugify($b->getName(), null), $percent);
        $sameTown = null !== $a->getCity() && Venue::slugify($a->getCity(), null) === Venue::slugify((string) $b->getCity(), null);

        return (int) min(100, round($percent + ($sameTown ? 15 : 0)));
    }
}
