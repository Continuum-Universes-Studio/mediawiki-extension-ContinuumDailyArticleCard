<?php

declare( strict_types = 1 );

namespace ContinuumUniverses\DailyArticleCard\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use ContinuumUniverses\DailyArticleCard\Hooks;
require_once dirname( __DIR__, 4 ) . '/maintenance/Maintenance.php';

class CycleDailyArticleCard extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Advance the cached DailyArticleCard selection to the next eligible article.'
		);

		$this->addOption(
			'date',
			'Target one date in YYYY-MM-DD format. Defaults to today in the wiki timezone.',
			false,
			true
		);

		$this->addOption(
			'steps',
			'Number of eligible articles to advance. Defaults to 1.',
			false,
			true
		);

		$this->addOption(
			'force',
			'Cycle again even if this wiki day was already advanced.',
			false,
			false
		);
	}

	public function execute(): void {
		$baseDate = $this->getOption( 'date', Hooks::getCurrentDayKey() );
		$steps = max( 1, (int)$this->getOption( 'steps', 1 ) );

		$dt = \DateTimeImmutable::createFromFormat(
			'Y-m-d',
			$baseDate,
			new \DateTimeZone( \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( \MediaWiki\MainConfigNames::Localtimezone ) ?: 'UTC' )
		);
		if ( !$dt || $dt->format( 'Y-m-d' ) !== $baseDate ) {
			$this->fatalError( "Invalid --date value: {$baseDate}" );
		}

		if ( !$this->hasOption( 'force' )
			&& Hooks::hasCycledForDay( $baseDate )
		) {
			$this->output( "Already cycled {$baseDate}\n" );
			return;
		}

		$title = Hooks::cycleDailyArticleCardForDay( $baseDate, $steps );
		if ( !$title ) {
			$this->fatalError( 'Unable to cycle DailyArticleCard to another eligible article.' );
		}

		Hooks::markCycledForDay( $baseDate );

		$purgedCount = Hooks::purgePagesUsingCard();

		$this->output( "Cycled {$baseDate} to {$title->getPrefixedText()}\n" );
		$this->output( "Purged {$purgedCount} page(s) using the card\n" );
	}
}

$maintClass = CycleDailyArticleCard::class;
require_once RUN_MAINTENANCE_IF_MAIN;
