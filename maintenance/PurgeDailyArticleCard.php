<?php

declare( strict_types = 1 );

namespace ContinuumUniverses\DailyArticleCard\Maintenance;

use MediaWiki\MediaWikiServices;
use MediaWiki\Maintenance\Maintenance;
use ContinuumUniverses\DailyArticleCard\Hooks;
require_once dirname( __DIR__, 4 ) . '/maintenance/Maintenance.php';

class PurgeDailyArticleCard extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Purge or rebuild cached DailyArticleCard HTML for one or more dates.'
		);

		$this->addOption(
			'date',
			'Target one date in YYYY-MM-DD format. Defaults to today in the wiki timezone.',
			false,
			true
		);

		$this->addOption(
			'days',
			'Number of recent wiki days to purge, ending on --date or today.',
			false,
			true
		);

		$this->addOption(
			'version',
			'Cache key version segment. Defaults to the extension cache version.',
			false,
			true
		);

		$this->addOption(
			'rebuild',
			'After purging, regenerate the cache immediately.',
			false,
			false
		);
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		$version = $this->getOption( 'version', \ContinuumUniverses\ContinuumDailyArticleCard\Hooks::CACHE_VERSION );
		$baseDate = $this->getOption( 'date', \ContinuumUniverses\ContinuumDailyArticleCard\Hooks::getCurrentDayKey() );
		$days = max( 1, (int)$this->getOption( 'days', 1 ) );

		$dt = \DateTimeImmutable::createFromFormat(
			'Y-m-d',
			$baseDate,
			new \DateTimeZone( \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( \MediaWiki\MainConfigNames::Localtimezone ) ?: 'UTC' )
		);
		if ( !$dt || $dt->format( 'Y-m-d' ) !== $baseDate ) {
			$this->fatalError( "Invalid --date value: {$baseDate}" );
		}

		for ( $i = 0; $i < $days; $i++ ) {
			$targetDate = $dt->modify( "-{$i} days" )->format( 'Y-m-d' );
			$key = $cache->makeKey( 'dailyarticlecard', 'html', $version, $targetDate );

			$cache->delete( $key );
			$this->output( "Purged cache key for {$targetDate}\n" );

			if ( $this->hasOption( 'rebuild' ) ) {
				$html = \ContinuumUniverses\ContinuumDailyArticleCard\Hooks::buildCardHtmlForDay( $targetDate );
				$cache->set( $key, $html, $cache::TTL_DAY );
				$this->output( "Rebuilt cache for {$targetDate}\n" );
			}
		}

		if ( $this->hasOption( 'rebuild' ) ) {
			$purgedCount = \ContinuumUniverses\ContinuumDailyArticleCard\Hooks::purgePagesUsingCard();
			$this->output( "Purged {$purgedCount} page(s) using the card\n" );
		}
	}
}

$maintClass = PurgeDailyArticleCard::class;
require_once RUN_MAINTENANCE_IF_MAIN;
