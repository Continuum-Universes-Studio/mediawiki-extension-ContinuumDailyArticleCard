<?php

declare( strict_types = 1 );

namespace ContinuumUniverses\DailyArticleCard;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class Hooks {

	public const CACHE_VERSION = 'v5';
	private const FALLBACK_IMAGE = 'Mannequin.png';
	private const FALLBACK_DESCRIPTION = 'Explore this featured article from the Continuum Universes Wiki.';
	private const DESCRIPTION_PROP = 'dailyarticlecard-desc';
	private const USAGE_PROP = 'dailyarticlecard-used';
	private const USAGE_TRACKING_CATEGORY = 'dailyarticlecard-usage-category';
	private const DISAMBIGUATION_CATEGORY = 'Disambiguation';
	private const CYCLE_MARKER_KEY = 'cycled-day';
	
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setFunctionHook(
			'dailyarticlecard',
			[ self::class, 'renderDailyArticleCard' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			'dailydesc',
			[ self::class, 'setDailyDescription' ],
			Parser::SFH_OBJECT_ARGS
		);
	}

	public static function setDailyDescription( Parser $parser, PPFrame $frame, array $args ): string {
		$description = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : '';
		self::setDailyDescriptionProperty( $parser->getOutput(), $description );
		return '';
	}

	public static function renderDailyArticleCard( Parser $parser, PPFrame $frame, array $args ): array {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		$dayKey = self::getCurrentDayKey();
		$cacheKey = self::getHtmlCacheKey( $cache, $dayKey );

		$html = $cache->getWithSetCallback(
			$cacheKey,
			$cache::TTL_DAY,
			static function () use ( $dayKey ) {
				return self::buildCardHtmlForDay( $dayKey );
			}
		);

		self::setDailyUsageProperty( $parser->getOutput() );
		$parser->addTrackingCategory( self::USAGE_TRACKING_CATEGORY );
		$parser->getOutput()->updateCacheExpiry( self::secondsUntilNextLocalMidnight() );

		return [ $html, 'noparse' => true, 'isHTML' => true ];
	}

	public static function buildCardHtmlForDay( string $dayKey ): string {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		$row = self::getStoredSelectionRowForDay( $cache, $dbr, $dayKey );
		if ( !$row ) {
			$row = self::selectDefaultEligiblePageRowForDay( $dbr, $dayKey );
		}

		if ( !$row ) {
			return Html::rawElement(
				'div',
				[ 'class' => 'daily-article-card daily-article-card--error' ],
				'Unable to select an article.'
			);
		}

		self::rememberSelectionForDay( $cache, $dayKey, (int)$row->page_id );

		return self::buildCardHtmlFromRow( $dbr, $row );
	}

	public static function cycleDailyArticleCardForDay( string $dayKey, int $steps = 1 ): ?Title {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		$row = self::getStoredSelectionRowForDay( $cache, $dbr, $dayKey );
		if ( !$row ) {
			$row = self::selectDefaultEligiblePageRowForDay( $dbr, $dayKey );
		}

		if ( !$row ) {
			return null;
		}

		for ( $i = 0; $i < max( 1, $steps ); $i++ ) {
			$nextRow = self::selectNextEligiblePageRowAfter( $dbr, (int)$row->page_id );
			if ( !$nextRow ) {
				return null;
			}
			$row = $nextRow;
		}

		self::rememberSelectionForDay( $cache, $dayKey, (int)$row->page_id );

		$html = self::buildCardHtmlFromRow( $dbr, $row );
		$cache->set( self::getHtmlCacheKey( $cache, $dayKey ), $html, $cache::TTL_DAY );

		$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
		return $title ?: null;
	}

	public static function purgePagesUsingCard(): int {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		$wikiPageFactory = $services->getWikiPageFactory();
		$trackingCategoryDbKey = Title::makeTitle(
			NS_CATEGORY,
			wfMessage( 'dailyarticlecard-usage-category' )->inContentLanguage()->text()
		)->getDBkey();

		$pageIds = [];

		$usedByProp = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id' ] )
			->from( 'page' )
			->join( 'page_props', 'pp_used', [
				'pp_used.pp_page = page_id',
				'pp_used.pp_propname' => self::USAGE_PROP
			] )
			->where( [ 'page_namespace' => NS_MAIN ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $usedByProp as $row ) {
			$pageIds[(int)$row->page_id] = true;
		}

		$usedByCategory = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id' ] )
			->from( 'page' )
			->join( 'categorylinks', 'cl_used', [ 'cl_used.cl_from = page_id' ] )
			->join( 'linktarget', 'lt_used', [
				'cl_used.cl_target_id = lt_used.lt_id',
				'lt_used.lt_namespace' => NS_CATEGORY,
				'lt_used.lt_title' => $trackingCategoryDbKey
			] )
			->where( [ 'page_namespace' => NS_MAIN ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $usedByCategory as $row ) {
			$pageIds[(int)$row->page_id] = true;
		}

		$count = 0;
		foreach ( array_keys( $pageIds ) as $pageId ) {
			$wikiPage = $wikiPageFactory->newFromID( $pageId );
			if ( !$wikiPage ) {
				continue;
			}

			$wikiPage->doPurge();
			$count++;
		}

		return $count;
	}

	public static function hasCycledForDay( string $dayKey ): bool {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		return (bool)$cache->get( self::getCycleMarkerCacheKey( $cache, $dayKey ) );
	}

	public static function markCycledForDay( string $dayKey ): void {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->set(
			self::getCycleMarkerCacheKey( $cache, $dayKey ),
			1,
			self::secondsUntilNextLocalMidnight()
		);
	}

	public static function getCurrentDayKey(): string {
		return ( new \DateTimeImmutable( 'now', self::getLocalTimeZone() ) )->format( 'Y-m-d' );
	}

	private static function renderCardMarkup( Title $title, string $shortDescHtml, string $imageHtml ): string {
		$link = $title->getLocalURL();
		$descHtml = Html::rawElement(
			'div',
			[ 'class' => 'daily-article-card__desc' ],
			$shortDescHtml
		);

		return Html::rawElement(
			'div',
			[ 'class' => 'daily-article-card' ],
			$imageHtml .
			Html::rawElement(
				'div',
				[ 'class' => 'daily-article-card__body' ],
				Html::rawElement(
					'div',
					[ 'class' => 'daily-article-card__eyebrow' ],
					"Today's featured article"
				) .
				Html::rawElement(
					'div',
					[ 'class' => 'daily-article-card__title' ],
					Html::rawElement(
						'a',
						[
							'href' => $link,
							'class' => 'daily-article-card__title-link'
						],
						htmlspecialchars( $title->getText() )
					)
				) .
				$descHtml .
				Html::rawElement(
					'div',
					[ 'class' => 'daily-article-card__actions' ],
					Html::rawElement(
						'a',
						[
							'href' => $link,
							'class' => 'daily-article-card__button'
						],
						'Read article'
					)
				)
			)
		);
	}

	private static function getStoredSelectionRowForDay(
		WANObjectCache $cache,
		IReadableDatabase $dbr,
		string $dayKey
	): ?object {
		$pageId = $cache->get( self::getSelectionCacheKey( $cache, $dayKey ) );
		if ( !is_numeric( $pageId ) ) {
			return null;
		}

		return self::getEligiblePageRowById( $dbr, (int)$pageId );
	}

	private static function rememberSelectionForDay( WANObjectCache $cache, string $dayKey, int $pageId ): void {
		$cache->set(
			self::getSelectionCacheKey( $cache, $dayKey ),
			$pageId,
			$cache::TTL_DAY
		);
	}

	private static function getEligiblePageRowById( IReadableDatabase $dbr, int $pageId ): ?object {
		return self::newEligiblePageQuery( $dbr )
			->where( [ 'page_id' => $pageId ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow() ?: null;
	}

	private static function selectDefaultEligiblePageRowForDay( IReadableDatabase $dbr, string $dayKey ): ?object {
		$count = (int)self::newEligiblePageBaseQuery( $dbr )
			->select( 'COUNT(*)' )
			->caller( __METHOD__ )
			->fetchField();

		if ( $count < 1 ) {
			return null;
		}

		$offset = abs( crc32( $dayKey ) ) % $count;
		return self::newEligiblePageQuery( $dbr )
			->orderBy( 'page_id', SelectQueryBuilder::SORT_ASC )
			->offset( $offset )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow() ?: null;
	}

	private static function selectNextEligiblePageRowAfter( IReadableDatabase $dbr, int $pageId ): ?object {
		$row = self::newEligiblePageQuery( $dbr )
			->where( $dbr->expr( 'page_id', '>', $pageId ) )
			->orderBy( 'page_id', SelectQueryBuilder::SORT_ASC )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row ) {
			return $row;
		}

		return self::newEligiblePageQuery( $dbr )
			->orderBy( 'page_id', SelectQueryBuilder::SORT_ASC )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow() ?: null;
	}

	private static function newEligiblePageQuery( IReadableDatabase $dbr ): SelectQueryBuilder {
		return self::newEligiblePageBaseQuery( $dbr )
			->select( [ 'page_id', 'page_namespace', 'page_title' ] );
	}

	private static function newEligiblePageBaseQuery( IReadableDatabase $dbr ): SelectQueryBuilder {
		return $dbr->newSelectQueryBuilder()
			->from( 'page' )
			->leftJoin( 'categorylinks', 'cl_disambig', [
				'cl_disambig.cl_from = page_id'
			] )
			->leftJoin( 'linktarget', 'lt_disambig', [
				'cl_disambig.cl_target_id = lt_disambig.lt_id',
				'lt_disambig.lt_namespace' => NS_CATEGORY,
				'lt_disambig.lt_title' => self::getDisambiguationCategoryDbKey()
			] )
			->leftJoin( 'page_props', 'pp_disambig', [
				'pp_disambig.pp_page = page_id',
				'pp_disambig.pp_propname' => 'disambiguation'
			] )
			->where( [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
				'lt_disambig.lt_id' => null,
				'pp_disambig.pp_page' => null
			] );
	}

	private static function getDisambiguationCategoryDbKey(): string {
		return Title::makeTitle( NS_CATEGORY, self::DISAMBIGUATION_CATEGORY )->getDBkey();
	}

	private static function getSelectionCacheKey( WANObjectCache $cache, string $dayKey ): string {
		return $cache->makeKey( 'dailyarticlecard', 'selected-page', self::CACHE_VERSION, $dayKey );
	}

	private static function getHtmlCacheKey( WANObjectCache $cache, string $dayKey ): string {
		return $cache->makeKey( 'dailyarticlecard', 'html', self::CACHE_VERSION, $dayKey );
	}

	private static function getCycleMarkerCacheKey( WANObjectCache $cache, string $dayKey ): string {
		return $cache->makeKey( 'dailyarticlecard', self::CYCLE_MARKER_KEY, self::CACHE_VERSION, $dayKey );
	}

	private static function getPageProps( IReadableDatabase $dbr, int $pageId ): array {
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'pp_propname', 'pp_value' ] )
			->from( 'page_props' )
			->where( [
				'pp_page' => $pageId,
				'pp_propname' => [ 'page_image', 'page_image_free', self::DESCRIPTION_PROP ]
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$props = [];
		foreach ( $res as $row ) {
			$props[(string)$row->pp_propname] = (string)$row->pp_value;
		}

		return $props;
	}

	private static function setDailyDescriptionProperty( ParserOutput $output, string $description ): void {
		if ( $description === '' ) {
			$output->unsetPageProperty( self::DESCRIPTION_PROP );
			return;
		}

		$output->setPageProperty( self::DESCRIPTION_PROP, $description );
	}

	private static function setDailyUsageProperty( ParserOutput $output ): void {
		$output->setPageProperty( self::USAGE_PROP, self::CACHE_VERSION );
	}

	private static function secondsUntilNextLocalMidnight(): int {
		$now = new \DateTimeImmutable( 'now', self::getLocalTimeZone() );
		$midnight = $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );
		return max( 300, $midnight->getTimestamp() - $now->getTimestamp() );
	}

	private static function getLocalTimeZone(): \DateTimeZone {
		$timezone = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::Localtimezone );
		return new \DateTimeZone( $timezone ?: 'UTC' );
	}

	private static function buildImageHtml( ?string $imageName, Title $title ): string {
		if ( !$imageName ) {
			$imageName = self::FALLBACK_IMAGE;
		}

		$file = MediaWikiServices::getInstance()
			->getRepoGroup()
			->findFile( $imageName );

		if ( !$file ) {
			return '';
		}

		$thumb = $file->transform( [ 'width' => 720 ] );
		if ( !$thumb || !$thumb->getUrl() ) {
			return '';
		}

		return Html::rawElement(
			'a',
			[
				'href' => $title->getLocalURL(),
				'class' => 'daily-article-card__image-link'
			],
			Html::element(
				'img',
				[
					'src' => $thumb->getUrl(),
					'alt' => $title->getText(),
					'class' => 'daily-article-card__image',
					'loading' => 'lazy'
				]
			)
		);
	}

	private static function buildCardHtmlFromRow( IReadableDatabase $dbr, object $row ): string {
		$pageId = (int)$row->page_id;
		$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );

		if ( !$title ) {
			return Html::rawElement(
				'div',
				[ 'class' => 'daily-article-card daily-article-card--error' ],
				'Unable to create title object.'
			);
		}

		$pageProps = self::getPageProps( $dbr, $pageId );

		$imageName = $pageProps['page_image_free']
			?? $pageProps['page_image']
			?? self::FALLBACK_IMAGE;

		$description = trim( $pageProps[self::DESCRIPTION_PROP] ?? '' );
		if ( $description === '' ) {
			$description = self::FALLBACK_DESCRIPTION;
		}

		$descriptionHtml = self::buildDescriptionHtml( $title, $description );
		$imageHtml = self::buildImageHtml( $imageName, $title );
		if ( $imageHtml === '' && $imageName !== self::FALLBACK_IMAGE ) {
			$imageHtml = self::buildImageHtml( self::FALLBACK_IMAGE, $title );
		}

		return self::renderCardMarkup( $title, $descriptionHtml, $imageHtml );
	}

	private static function buildDescriptionHtml( Title $title, string $description ): string {
		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		$parserOutput = $parser->parse( $description, $title, ParserOptions::newFromAnon() );

		return Parser::stripOuterParagraph( $parserOutput->getContentHolderText() );
	}
	public static function onRegistration(): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( 'ContinuumPoweredByBadges' ) ) {
			return;
		}


		$scriptPath = $GLOBALS['wgScriptPath'] ?? '';
		$base = rtrim( $scriptPath, '/' ) . '/extensions/ContinuumDailyArticleCard/resources/assets';

		$GLOBALS['wgFooterIcons']['poweredby'] ??= [];

		$GLOBALS['wgFooterIcons']['poweredby']['continuum-universes'] = [
			'src' => "$base/poweredby-continuum.svg",
			'url' => 'https://continuum-universes.com/',
			'alt' => 'Powered by Continuum',
		];
	}
}
