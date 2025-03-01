<?php

use MediaWiki\MainConfigNames;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers DifferenceEngine
 *
 * @todo tests for the rest of DifferenceEngine!
 *
 * @group Database
 * @group Diff
 *
 * @author Katie Filbert < aude.wiki@gmail.com >
 */
class DifferenceEngineTest extends MediaWikiIntegrationTestCase {
	use MockTitleTrait;

	protected $context;

	private static $revisions;

	protected function setUp(): void {
		parent::setUp();

		$title = $this->getTitle();

		$this->context = new RequestContext();
		$this->context->setTitle( $title );

		if ( !self::$revisions ) {
			self::$revisions = $this->doEdits();
		}

		$this->overrideConfigValue( MainConfigNames::DiffEngine, 'php' );

		$slotRoleRegistry = $this->getServiceContainer()->getSlotRoleRegistry();

		if ( !$slotRoleRegistry->isDefinedRole( 'derivedslot' ) ) {
			$slotRoleRegistry->defineRoleWithModel(
				'derivedslot',
				CONTENT_MODEL_WIKITEXT,
				[],
				true
			);
		}
	}

	/**
	 * @return Title
	 */
	protected function getTitle() {
		$namespace = $this->getDefaultWikitextNS();
		return Title::makeTitle( $namespace, 'Kitten' );
	}

	/**
	 * @return int[] Revision ids
	 */
	protected function doEdits() {
		$title = $this->getTitle();

		$strings = [ "it is a kitten", "two kittens", "three kittens", "four kittens" ];
		$revisions = [];

		$user = $this->getTestSysop()->getAuthority();
		foreach ( $strings as $string ) {
			$status = $this->editPage(
				$title,
				$string,
				'edit page',
				NS_MAIN,
				$user
			);
			$revisions[] = $status->getNewRevision()->getId();
		}

		return $revisions;
	}

	public function testMapDiffPrevNext() {
		$cases = $this->getMapDiffPrevNextCases();

		foreach ( $cases as [ $expected, $old, $new, $message ] ) {
			$diffEngine = new DifferenceEngine( $this->context, $old, $new, 2, true, false );
			$diffMap = $diffEngine->mapDiffPrevNext( $old, $new );
			$this->assertEquals( $expected, $diffMap, $message );
		}
	}

	private function getMapDiffPrevNextCases() {
		$revs = self::$revisions;

		return [
			[ [ $revs[1], $revs[2] ], $revs[2], 'prev', 'diff=prev' ],
			[ [ $revs[2], $revs[3] ], $revs[2], 'next', 'diff=next' ],
			[ [ $revs[1], $revs[3] ], $revs[1], $revs[3], 'diff=' . $revs[3] ]
		];
	}

	public function testLoadRevisionData() {
		$cases = $this->getLoadRevisionDataCases();

		foreach ( $cases as $testName => [ $expectedOld, $expectedNew, $expectedRet, $old, $new ] ) {
			$diffEngine = new DifferenceEngine( $this->context, $old, $new, 2, true, false );
			$ret = $diffEngine->loadRevisionData();
			$ret2 = $diffEngine->loadRevisionData();

			$this->assertEquals( $expectedOld, $diffEngine->getOldid(), $testName );
			$this->assertEquals( $expectedNew, $diffEngine->getNewid(), $testName );
			$this->assertEquals( $expectedRet, $ret, $testName );
			$this->assertEquals( $expectedRet, $ret2, $testName );
		}
	}

	private function getLoadRevisionDataCases() {
		$revs = self::$revisions;

		return [
			'diff=prev' => [ $revs[2], $revs[3], true, $revs[3], 'prev' ],
			'diff=next' => [ $revs[2], $revs[3], true, $revs[2], 'next' ],
			'diff=' . $revs[3] => [ $revs[1], $revs[3], true, $revs[1], $revs[3] ],
			'diff=0' => [ $revs[1], $revs[3], true, $revs[1], 0 ],
			'diff=prev&oldid=<first>' => [ false, $revs[0], true, $revs[0], 'prev' ],
			'invalid' => [ 123456789, $revs[1], false, 123456789, $revs[1] ],
		];
	}

	public function testGetOldid() {
		$revs = self::$revisions;

		$diffEngine = new DifferenceEngine( $this->context, $revs[1], $revs[2], 2, true, false );
		$this->assertEquals( $revs[1], $diffEngine->getOldid(), 'diff get old id' );
	}

	public function testGetNewid() {
		$revs = self::$revisions;

		$diffEngine = new DifferenceEngine( $this->context, $revs[1], $revs[2], 2, true, false );
		$this->assertEquals( $revs[2], $diffEngine->getNewid(), 'diff get new id' );
	}

	public function provideLocaliseTitleTooltipsTestData() {
		return [
			'moved paragraph left shoud get new location title' => [
				'<a class="mw-diff-movedpara-left">⚫</a>',
				'<a class="mw-diff-movedpara-left" title="(diff-paragraph-moved-tonew)">⚫</a>',
			],
			'moved paragraph right shoud get old location title' => [
				'<a class="mw-diff-movedpara-right">⚫</a>',
				'<a class="mw-diff-movedpara-right" title="(diff-paragraph-moved-toold)">⚫</a>',
			],
			'nothing changed when key not hit' => [
				'<a class="mw-diff-movedpara-rightis">⚫</a>',
				'<a class="mw-diff-movedpara-rightis">⚫</a>',
			],
		];
	}

	/**
	 * @dataProvider provideLocaliseTitleTooltipsTestData
	 */
	public function testAddLocalisedTitleTooltips( $input, $expected ) {
		$this->setContentLang( 'qqx' );
		/** @var DifferenceEngine $diffEngine */
		$diffEngine = TestingAccessWrapper::newFromObject( new DifferenceEngine() );
		$this->assertEquals( $expected, $diffEngine->addLocalisedTitleTooltips( $input ) );
	}

	/**
	 * @dataProvider provideGenerateContentDiffBody
	 */
	public function testGenerateContentDiffBody(
		array $oldContentArgs, array $newContentArgs, $expectedDiff
	) {
		$this->mergeMwGlobalArrayValue( 'wgContentHandlers', [
			'testing-nontext' => DummyNonTextContentHandler::class,
		] );
		$oldContent = ContentHandler::makeContent( ...$oldContentArgs );
		$newContent = ContentHandler::makeContent( ...$newContentArgs );

		$differenceEngine = new DifferenceEngine();
		$diff = $differenceEngine->generateContentDiffBody( $oldContent, $newContent );
		$this->assertSame( $expectedDiff, $this->getPlainDiff( $diff ) );
	}

	public static function provideGenerateContentDiffBody() {
		$content1 = [ 'xxx', null, CONTENT_MODEL_TEXT ];
		$content2 = [ 'yyy', null, CONTENT_MODEL_TEXT ];

		return [
			'self-diff' => [ $content1, $content1, '' ],
			'text diff' => [ $content1, $content2, '-xxx+yyy' ],
		];
	}

	public function testGenerateTextDiffBody() {
		$oldText = "aaa\nbbb\nccc";
		$newText = "aaa\nxxx\nccc";
		$expectedDiff = " aaa aaa\n-bbb+xxx\n ccc ccc";

		$differenceEngine = new DifferenceEngine();
		$diff = $differenceEngine->generateTextDiffBody( $oldText, $newText );
		$this->assertSame( $expectedDiff, $this->getPlainDiff( $diff ) );
	}

	public function testSetContent() {
		$oldContent = ContentHandler::makeContent( 'xxx', null, CONTENT_MODEL_TEXT );
		$newContent = ContentHandler::makeContent( 'yyy', null, CONTENT_MODEL_TEXT );

		$differenceEngine = new DifferenceEngine();
		$differenceEngine->setContent( $oldContent, $newContent );
		$diff = $differenceEngine->getDiffBody();
		$this->assertSame( "Line 1:\nLine 1:\n-xxx+yyy", $this->getPlainDiff( $diff ) );
	}

	public function testSetRevisions() {
		$main1 = SlotRecord::newUnsaved( SlotRecord::MAIN,
			ContentHandler::makeContent( 'xxx', null, CONTENT_MODEL_TEXT ) );
		$main2 = SlotRecord::newUnsaved( SlotRecord::MAIN,
			ContentHandler::makeContent( 'yyy', null, CONTENT_MODEL_TEXT ) );
		$rev1 = $this->getRevisionRecord( $main1 );
		$rev2 = $this->getRevisionRecord( $main2 );

		$differenceEngine = new DifferenceEngine();
		$differenceEngine->setRevisions( $rev1, $rev2 );
		$this->assertSame( $rev1, $differenceEngine->getOldRevision() );
		$this->assertSame( $rev2, $differenceEngine->getNewRevision() );
		$this->assertSame( true, $differenceEngine->loadRevisionData() );
		$this->assertSame( true, $differenceEngine->loadText() );

		$differenceEngine->setRevisions( null, $rev2 );
		$this->assertSame( null, $differenceEngine->getOldRevision() );
	}

	/**
	 * @dataProvider provideGetDiffBody
	 */
	public function testGetDiffBody(
		?RevisionRecord $oldRevision, ?RevisionRecord $newRevision, $expectedDiff
	) {
		if ( $expectedDiff instanceof Exception ) {
			$this->expectException( get_class( $expectedDiff ) );
			$this->expectExceptionMessage( $expectedDiff->getMessage() );
		}
		$differenceEngine = new DifferenceEngine();
		$differenceEngine->setRevisions( $oldRevision, $newRevision );
		if ( $expectedDiff instanceof Exception ) {
			return;
		}

		$diff = $differenceEngine->getDiffBody();
		$this->assertSame( $expectedDiff, $this->getPlainDiff( $diff ) );
	}

	public function provideGetDiffBody() {
		$main1 = SlotRecord::newUnsaved( SlotRecord::MAIN,
			ContentHandler::makeContent( 'xxx', null, CONTENT_MODEL_TEXT ) );
		$main2 = SlotRecord::newUnsaved( SlotRecord::MAIN,
			ContentHandler::makeContent( 'yyy', null, CONTENT_MODEL_TEXT ) );
		$slot1 = SlotRecord::newUnsaved( 'slot',
			ContentHandler::makeContent( 'aaa', null, CONTENT_MODEL_TEXT ) );
		$slot2 = SlotRecord::newUnsaved( 'slot',
			ContentHandler::makeContent( 'bbb', null, CONTENT_MODEL_TEXT ) );
		$slot3 = SlotRecord::newDerived( 'derivedslot',
			ContentHandler::makeContent( 'aaa', null, CONTENT_MODEL_TEXT ) );
		$slot4 = SlotRecord::newDerived( 'derivedslot',
			ContentHandler::makeContent( 'bbb', null, CONTENT_MODEL_TEXT ) );

		return [
			'revision vs. null' => [
				null,
				$this->getRevisionRecord( $main1, $slot1 ),
				'',
			],
			'revision vs. itself' => [
				$this->getRevisionRecord( $main1, $slot1 ),
				$this->getRevisionRecord( $main1, $slot1 ),
				'',
			],
			'different text in one slot' => [
				$this->getRevisionRecord( $main1, $slot1 ),
				$this->getRevisionRecord( $main1, $slot2 ),
				"slotLine 1:\nLine 1:\n-aaa+bbb",
			],
			'different text in two slots' => [
				$this->getRevisionRecord( $main1, $slot1 ),
				$this->getRevisionRecord( $main2, $slot2 ),
				"Line 1:\nLine 1:\n-xxx+yyy\nslotLine 1:\nLine 1:\n-aaa+bbb",
			],
			'new slot' => [
				$this->getRevisionRecord( $main1 ),
				$this->getRevisionRecord( $main1, $slot1 ),
				"slotLine 1:\nLine 1:\n- +aaa",
			],
			'ignored difference in derived slot' => [
				$this->getRevisionRecord( $main1, $slot3 ),
				$this->getRevisionRecord( $main1, $slot4 ),
				'',
			],
		];
	}

	public function testRecursion() {
		// Set up a ContentHandler which will return a wrapped DifferenceEngine as
		// SlotDiffRenderer, then pass it a content which uses the same ContentHandler.
		// This tests the anti-recursion logic in DifferenceEngine::generateContentDiffBody.

		$customDifferenceEngine = $this->getMockBuilder( DifferenceEngine::class )
			->enableProxyingToOriginalMethods()
			->getMock();
		$customContentHandler = $this->getMockBuilder( ContentHandler::class )
			->setConstructorArgs( [ 'foo', [] ] )
			->onlyMethods( [ 'createDifferenceEngine' ] )
			->getMockForAbstractClass();
		$customContentHandler->method( 'createDifferenceEngine' )
			->willReturn( $customDifferenceEngine );
		/** @var ContentHandler $customContentHandler */
		$customContent = $this->getMockBuilder( Content::class )
			->onlyMethods( [ 'getContentHandler' ] )
			->getMockForAbstractClass();
		$customContent->method( 'getContentHandler' )
			->willReturn( $customContentHandler );
		/** @var Content $customContent */
		$customContent2 = clone $customContent;

		$slotDiffRenderer = $customContentHandler->getSlotDiffRenderer( RequestContext::getMain() );
		$this->expectException( Exception::class );
		$this->expectExceptionMessage(
			': could not maintain backwards compatibility. Please use a SlotDiffRenderer.'
		);
		$slotDiffRenderer->getDiff( $customContent, $customContent2 );
	}

	/**
	 * @dataProvider provideMarkPatrolledLink
	 */
	public function testMarkPatrolledLink( $group, $config, $expectedResult ) {
		$this->setUserLang( 'qqx' );
		$user = $this->getTestUser( $group )->getUser();
		$this->context->setUser( $user );
		if ( $config ) {
			$this->context->setConfig( $config );
		}

		$page = $this->getNonexistingTestPage( 'Page1' );
		$this->assertTrue( $this->editPage( $page, 'Edit1' )->isGood(), 'edited a page' );
		$rev1 = $page->getRevisionRecord();
		$this->assertTrue( $this->editPage( $page, 'Edit2' )->isGood(), 'edited a page' );
		$rev2 = $page->getRevisionRecord();

		$diffEngine = new DifferenceEngine( $this->context );
		$diffEngine->setRevisions( $rev1, $rev2 );

		$html = $diffEngine->markPatrolledLink();
		$this->assertStringContainsString( $expectedResult, $html );
	}

	public function provideMarkPatrolledLink() {
		yield 'PatrollingEnabledUserAllowed' => [
			'sysop',
			new HashConfig( [ 'UseRCPatrol' => true, 'LanguageCode' => 'qxx' ] ),
			'Mark as patrolled'
		];

		yield 'PatrollingEnabledUserNotAllowed' => [
			null,
			new HashConfig( [ 'UseRCPatrol' => true, 'LanguageCode' => 'qxx' ] ),
			''
		];

		yield 'PatrollingDisabledUserAllowed' => [
			'sysop',
			null,
			''
		];

		yield 'PatrollingDisabledUserNotAllowed' => [
			null,
			null,
			''
		];
	}

	/**
	 * Convert a HTML diff to a human-readable format and hopefully make the test less fragile.
	 * @param string $diff
	 * @return string
	 */
	private function getPlainDiff( $diff ) {
		$replacements = [
			html_entity_decode( '&nbsp;' ) => ' ',
			html_entity_decode( '&minus;' ) => '-',
		];
		// Preserve markers when stripping tags
		$diff = str_replace( '<td class="diff-marker"></td>', ' ', $diff );
		$diff = str_replace( '<td colspan="2"></td>', ' ', $diff );
		$diff = preg_replace( '/data-marker="([^"]*)">/', '>$1', $diff );
		return str_replace( array_keys( $replacements ), array_values( $replacements ),
			trim( strip_tags( $diff ), "\n" ) );
	}

	/**
	 * @param SlotRecord ...$slots
	 * @return MutableRevisionRecord
	 */
	private function getRevisionRecord( ...$slots ) {
		$title = $this->makeMockTitle( __CLASS__ );
		$revision = new MutableRevisionRecord( $title );
		foreach ( $slots as $slot ) {
			$revision->setSlot( $slot );
		}
		return $revision;
	}

}
