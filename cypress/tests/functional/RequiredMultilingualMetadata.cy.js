/**
 * @file cypress/tests/functional/RequiredMultilingualMetadata.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: a submission is only accepted with the metadata filled in
 * every language the journal or press requires.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration. The first test enables the plugin when it is off. The rule is
 * exercised through the submit endpoint of the API, with a submission that is
 * created and deleted by the test; the settings are put back after the run, and
 * the check is skipped where the journal or press has a single metadata language.
 */

describe('Required Multilingual Metadata plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	// An author account of the journal or press, who submits in the test: the rule exempts
	// editorial staff working on someone else's submission, so an editor cannot exercise it.
	const authorUser = Cypress.env('authorUser');
	const authorPassword = Cypress.env('authorPassword');

	const row = 'requiredmultilingualmetadataplugin';
	const form = '#requiredMultilingualMetadataSettingsForm';
	const settingsUrl = () => pageUrl('$$$call$$$/grid/settings/plugins/settings-plugin-grid/manage') + '?verb=settings&plugin=' + row + '&category=generic&save=1';
	let originalSettings = null;
	let createdSubmissionId = null;

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	// The journal of contextPath with all its settings, and the CSRF token of the page.
	const withJournal = (callback) => {
		cy.window({timeout: 60000}).its('pkp.currentUser.csrfToken').then((token) => {
			api('/index.php/index/api/v1/contexts?count=100').then((list) => {
				const journal = list.items.find((item) => item.urlPath === contextPath);
				api(pageUrl('api/v1/contexts/' + journal.id)).then((details) => callback(details, token));
			});
		});
	};

	const postSettings = (fields) => cy.window({timeout: 60000}).its('pkp.currentUser.csrfToken').then((token) => request({
		method: 'POST',
		url: settingsUrl(),
		form: true,
		body: Object.assign({}, fields, {csrfToken: token}),
	}));

	// Reads the settings of the form as it stands, to put them back after the run.
	const readSettings = () => cy.window().then((win) => {
		if (originalSettings !== null) {
			return;
		}
		originalSettings = {};
		win.jQuery(form).serializeArray().filter((field) => field.name !== 'csrfToken').forEach((field) => {
			originalSettings[field.name] = originalSettings[field.name] || [];
			originalSettings[field.name].push(field.value);
		});
	});

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(row);
	});

	(authorUser ? it : it.skip)('Blocks the submission until the title is filled in every required language', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		openPluginSettings(row, form);
		readSettings();

		withJournal((journal, journalToken) => {
			// The token changes when the session below becomes the author's.
			let token = journalToken;
			const locales = journal.supportedSubmissionMetadataLocales || [];
			const submissionLocale = journal.supportedDefaultSubmissionLocale || locales[0];
			const extraLocale = locales.find((locale) => locale !== submissionLocale);
			if (!extraLocale) {
				cy.log('A single metadata language: the rule cannot be exercised here');
				return;
			}

			// The title becomes required in the extra language as well.
			postSettings({'titleLocales[]': extraLocale}).its('body.status').should('eq', true);

			// The section the submission goes to, read while the manager's session is still
			// open. OJS requires one; OMP has no endpoint for its series and does not.
			let sectionId = null;
			cy.window().then((win) => cy.wrap(
				win.fetch(pageUrl('api/v1/sections?count=1'), {credentials: 'same-origin'})
					.then((response) => response.ok ? response.json() : {items: []})
					.then((sections) => ((sections.items || [])[0] || {}).id || null),
				{log: false}
			)).then((id) => {
				sectionId = id;
			});

			// The submission is made by the author, who is the one the rule applies to.
			login(authorUser, authorPassword);
			cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
			cy.window({timeout: 60000}).its('pkp.currentUser.csrfToken').then((authorToken) => {
				token = authorToken;
			});

			cy.window().then((win) => cy.wrap((async() => {
				const call = (method, path, body) => win.fetch(path, {
					method: method,
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/json', 'X-Csrf-Token': token},
					body: body === undefined ? undefined : JSON.stringify(body),
				}).then(async(response) => ({status: response.status, body: await response.json()}));

				// A submission of the current user, who is therefore its author.
				const body = {locale: submissionLocale};
				if (sectionId) {
					body.sectionId = sectionId;
					body.seriesId = sectionId;
				}
				const created = await call('POST', pageUrl('api/v1/submissions'), body);
				if (created.status !== 200 && created.status !== 201) {
					return {error: 'submission not created: ' + created.status + ' ' + JSON.stringify(created.body).slice(0, 300)};
				}
				const submission = created.body;
				const publicationId = submission.currentPublicationId;

				// Title only in the submission language: the extra language is missing.
				await call('PUT', pageUrl('api/v1/submissions/' + submission.id + '/publications/' + publicationId), {
					title: {[submissionLocale]: 'OJSBR required multilingual metadata'},
				});
				const blocked = await call('PUT', pageUrl('api/v1/submissions/' + submission.id + '/submit'), {_validateOnly: true});

				// Now in both languages.
				await call('PUT', pageUrl('api/v1/submissions/' + submission.id + '/publications/' + publicationId), {
					title: {[submissionLocale]: 'OJSBR required multilingual metadata', [extraLocale]: 'OJSBR required multilingual metadata'},
				});
				const filled = await call('PUT', pageUrl('api/v1/submissions/' + submission.id + '/submit'), {_validateOnly: true});

				return {submissionId: submission.id, blocked: blocked, filled: filled};
			})(), {timeout: 60000})).then((result) => {
				expect(result.error, 'the submission of the test').to.be.undefined;
				createdSubmissionId = result.submissionId;

				// The plugin's error names the missing language, and nothing else was replaced.
				expect(result.blocked.body.title, 'errors of the title').to.not.be.undefined;
				expect(Object.keys(result.blocked.body.title)).to.include(extraLocale);
				expect(JSON.stringify(result.blocked.body.title[extraLocale])).to.not.contain('##');

				// With the title in both languages the plugin no longer complains.
				const stillMissing = (result.filled.body || {}).title;
				expect(stillMissing === undefined || !Object.keys(stillMissing).includes(extraLocale), 'the title is accepted').to.eq(true);
			});
		});
	});

	// Removes the submission of the test and puts the settings back, also when a test failed.
	after(function() {
		if (originalSettings === null && createdSubmissionId === null) {
			return;
		}
		login(adminUser, adminPassword);
		openPluginsTab();
		if (createdSubmissionId !== null) {
			cy.window().its('pkp.currentUser.csrfToken').then((token) => request({
				method: 'DELETE',
				url: pageUrl('api/v1/submissions/' + createdSubmissionId),
				headers: {'X-Csrf-Token': token},
				failOnStatusCode: false,
			}));
		}
		if (originalSettings !== null) {
			postSettings(originalSettings);
		}
	});
});
