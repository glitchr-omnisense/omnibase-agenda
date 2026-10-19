<?php

namespace Base\Agenda\Command;

use Base\Agenda\Model\Change;
use Base\Agenda\Service\IcsImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The calendars read into the agenda - agenda.sources, agenda.ics and the
 * address typed in the back office (/admin/agenda/calendar): new dates
 * online, changed ones brought up to date, gone ones held until someone
 * says whether they are cancelled. Run it from cron (every half hour);
 * what it did is kept for that page, which previews a sync before
 * applying it. --dry-run tells the changes and applies none; --source
 * reads one address or file instead, and is not kept.
 */
#[AsCommand(name: 'agenda:sync', description: 'Read the calendars (Google Calendar, ICS, Squarespace, another agenda feed) into the agenda.')]
final class SyncCommand extends Command
{
    public function __construct(private readonly IcsImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An address (ICS, Squarespace events page, /agenda.json) or a file to read instead of the configured calendars');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tell what would change, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $given = $input->getOption('source');
        $sources = $given ?: $this->importer->getSources();
        if (!$sources) {
            $io->note('No calendar to read: paste its address in the back office (Agenda › Google Calendar), set agenda.sources, or pass --source.');

            return Command::SUCCESS;
        }

        $dry = (bool) $input->getOption('dry-run');
        $summary = $this->importer->sync($sources, !$dry);
        if (!$given && !$dry) {
            $this->importer->remember($summary);
        }
        foreach ($summary->errors as $error) {
            $io->error($error);
        }
        if ($dry || $output->isVerbose()) {
            foreach ($summary->changes as $change) {
                $io->writeln(sprintf('<info>%s</info> %s  %s', $change->kind, $change->start, $change->title));
                foreach (Change::UPDATED === $change->kind || Change::CANCELLED === $change->kind ? $change->fields : [] as $field => [$before, $after]) {
                    $io->writeln(sprintf('    %s: %s → %s', $field, self::line($before), self::line($after)));
                }
            }
        }
        $io->definitionList(...array_map(fn ($k, $v) => [$k => $v], array_keys($summary->toArray()), $summary->toArray()));

        return $summary->errors && \count($summary->errors) === \count($sources) ? Command::FAILURE : Command::SUCCESS;
    }

    private static function line(?string $value): string
    {
        return null === $value ? '∅' : mb_strimwidth(str_replace("\n", ' / ', $value), 0, 80, '…');
    }
}
