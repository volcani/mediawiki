'use strict';

const assert = require( 'assert' );
const Api = require( 'wdio-mediawiki/Api' );
const DeletePage = require( '../pageobjects/delete.page' );
const RestorePage = require( '../pageobjects/restore.page' );
const EditPage = require( '../pageobjects/edit.page' );
const HistoryPage = require( '../pageobjects/history.page' );
const UndoPage = require( '../pageobjects/undo.page' );
const ProtectPage = require( '../pageobjects/protect.page' );
const UserLoginPage = require( 'wdio-mediawiki/LoginPage' );
const Util = require( 'wdio-mediawiki/Util' );

describe( 'Page', function () {
	let content, name, bot;

	before( async () => {
		bot = await Api.bot();
	} );

	beforeEach( async function () {
		await browser.deleteAllCookies();
		content = Util.getTestString( 'beforeEach-content-' );
		name = Util.getTestString( 'BeforeEach-name-' );
	} );

	it( 'should be previewable @daily', async function () {
		await UserLoginPage.loginAdmin();
		await EditPage.preview( name, content );

		assert.strictEqual( await EditPage.heading.getText(), 'Creating ' + name );
		assert.strictEqual( await EditPage.displayedContent.getText(), content );
		assert( await EditPage.content.isDisplayed(), 'editor is still present' );
		assert( !( await EditPage.conflictingContent.isDisplayed() ), 'no edit conflict happened' );

		// T269566: Popup with text
		// 'Leave site? Changes that you made may not be saved. Cancel/Leave'
		// appears after the browser tries to leave the page with the preview.
		await browser.reloadSession();
	} );

	it( 'should be creatable @daily', async function () {
		// create
		await UserLoginPage.loginAdmin();
		await EditPage.edit( name, content );

		// check
		assert.strictEqual( await EditPage.heading.getText(), name );
		assert.strictEqual( await EditPage.displayedContent.getText(), content );
	} );

	it( 'should be re-creatable @daily', async function () {
		const initialContent = Util.getTestString( 'initialContent-' );

		// create and delete
		await bot.edit( name, initialContent, 'create for delete' );
		await bot.delete( name, 'delete prior to recreate' );

		// re-create
		await UserLoginPage.loginAdmin();
		await EditPage.edit( name, content );

		// check
		assert.strictEqual( await EditPage.heading.getText(), name );
		assert.strictEqual( await EditPage.displayedContent.getText(), content );
	} );

	it( 'should be editable @daily', async function () {
		// create
		await bot.edit( name, content, 'create for edit' );

		// edit
		const editContent = Util.getTestString( 'editContent-' );
		await EditPage.edit( name, editContent );

		// check
		assert.strictEqual( await EditPage.heading.getText(), name );
		assert.match( await EditPage.displayedContent.getText(), new RegExp( editContent ) );
	} );

	it( 'should have history @daily', async function () {
		// create
		await bot.edit( name, content, `created with "${content}"` );

		// check
		await HistoryPage.open( name );
		assert.strictEqual( await HistoryPage.comment.getText(), `created with "${content}"` );
	} );

	it( 'should be deletable @daily', async function () {
		// create
		await bot.edit( name, content, 'create for delete' );

		// login
		await UserLoginPage.loginAdmin();
		// delete
		await DeletePage.delete( name, 'delete reason' );

		// check
		assert.match( await DeletePage.displayedContent.getText(), new RegExp( `"${name}" has been deleted.` ) );
	} );

	it( 'should be restorable @daily', async function () {
		// create and delete
		await bot.edit( name, content, 'create for delete' );
		await bot.delete( name, 'delete for restore' );

		// login
		await UserLoginPage.loginAdmin();

		// restore
		await RestorePage.restore( name, 'restore reason' );

		// check
		assert.strictEqual( await RestorePage.displayedContent.getText(), name + ' has been undeleted\n\nConsult the deletion log for a record of recent deletions and restorations.' );
	} );

	it( 'should be protectable @daily', async function () {

		await bot.edit( name, content, 'create for protect' );

		// login
		await UserLoginPage.loginAdmin();

		await ProtectPage.protect(
			name,
			'protect reason',
			'Allow only administrators'
		);

		// Logout
		await browser.deleteAllCookies();

		// Check that we can't edit the page anymore
		await EditPage.openForEditing( name );
		assert.strictEqual( await EditPage.save.isExisting(), false );
		assert.strictEqual( await EditPage.heading.getText(), 'View source for ' + name );
	} );

	it( 'should be undoable @daily', async function () {

		// create
		await bot.edit( name, content, 'create to edit and undo' );

		// edit
		const response = await bot.edit( name, Util.getTestString( 'editContent-' ) );
		const previousRev = response.edit.oldrevid;
		const undoRev = response.edit.newrevid;

		await UndoPage.undo( name, previousRev, undoRev );

		assert.strictEqual( await EditPage.displayedContent.getText(), content );
	} );

} );
