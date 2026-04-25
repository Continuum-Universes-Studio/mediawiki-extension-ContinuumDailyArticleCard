<?php

declare( strict_types = 1 );

namespace ContinuumUniverses\DailyArticleCard\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use ContinuumUniverses\DailyArticleCard\Hooks;
require_once dirname( __DIR__, 4 ) . '/maintenance/Maintenance.php';

class RefreshDailyArticleCard extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Refresh the cached DailyArticleCard HTML and image for one or more dates.'
		);

		$this->addOption(
			'date',
			'Target one date in YYYY-MM-DD format. Defaults to today in the wiki timezone.',
			false,
			true
		);

		$this->addOption(
			'days',
			'Number of recent wiki days to refresh, ending on --date or today.',
			false,
			true
		);
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		$baseDate = $this->getOption( 'date', Hooks::getCurrentDayKey() );
		$days = max( 1, (int)$this->getOption( 'days', 1 ) );

		$dt = \DateTimeImmutable::createFromFormat(
			'Y-m-d',
			$baseDate,
			new \DateTimeZone( \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( \MediaWiki\MainConfigNames::Localtimezone ) ?: 'UTC' )
		);
		if ( !$dt || $dt->format( 'Y-m-d' ) !== $baseDate ) {
			$this->fatalError( "Invalid --date value: {$baseDate}" );
		}

		foreach ( range( 0, $days - 1 ) as $i ) {
			$targetDate = $dt->modify( "-{$i} days" )->format( 'Y-m-d' );
			$key = $cache->makeKey(
				'dailyarticlecard',
				'html',
				Hooks::CACHE_VERSION,
				$targetDate
			);

			$html = Hooks::buildCardHtmlForDay( $targetDate );
			$cache->set( $key, $html, $cache::TTL_DAY );
			$this->output( "Refreshed cache for {$targetDate}\n" );
		}
	}
}

$maintClass = RefreshDailyArticleCard::class;
require_once RUN_MAINTENANCE_IF_MAIN;
