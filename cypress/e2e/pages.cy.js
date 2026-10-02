// Every page renders without console errors or uncaught exceptions. A lot of commented out
// code and debug logging was removed from these pages, this guards against a broken render.
// Console errors fail the test through the hook in cypress/support/index.js.
describe('Pages render', () => {
    const staticPages = [
        '/dashboard',
        '/record',
        '/record/create',
        '/records/trash',
        '/records-import',
        '/records-import/create',
        '/sale',
        '/sale/create',
        '/wholesale-in',
        '/wholesale-in/create',
        '/wholesale-out',
        '/wholesale-out/create',
        '/backorder',
        '/customer',
        '/customer/create',
        '/customers/trash',
        '/supplier',
        '/supplier/create',
        '/suppliers/trash',
        '/artist',
        '/artists/trash',
        '/label',
        '/labels/trash',
        '/format',
        '/formats/trash',
        '/location',
        '/location/create',
        '/locations/trash',
        '/area',
        '/area/create',
        '/areas/trash',
        '/user',
        '/user/create',
        '/users/trash',
        '/profile',
        '/user-manual',
    ];

    const editPages = [
        ['Record', 'title', 'Cypress Record', id => `/record/${id}/edit`],
        ['Record', 'title', 'Cypress Record', id => `/records/${id}/history`],
        ['WholesaleIn', 'doc_num', 'WI-OWN', id => `/wholesale-in/${id}/edit`],
        ['WholesaleOut', 'doc_num', 'WO-OWN', id => `/wholesale-out/${id}/edit`],
        ['Backorder', 'status', '0', id => `/backorder/${id}/edit`],
        ['RecordsImport', 'draft', '1', id => `/records-import/${id}/edit`],
        ['Sale', 'user_id', '1', id => `/sale/${id}/edit`],
        ['Customer', 'name', 'Cypress', id => `/customer/${id}/edit`],
        ['Supplier', 'name', 'Cypress Supplier', id => `/supplier/${id}/edit`],
        ['Location', 'name', 'Own Warehouse', id => `/location/${id}/edit`],
        ['Area', 'name', 'Own Store Area', id => `/area/${id}/edit`],
        ['User', 'email', 'operator@example.com', id => `/user/${id}/edit`],
    ];

    before(() => {
        cy.resetDatabase();
    });

    beforeEach(() => {
        cy.loginAs('admin@example.com');
    });

    for (const url of staticPages) {
        it(`renders ${url}`, () => {
            cy.visit(url);
            cy.get('#app').should('not.be.empty');
            cy.get('main, [class*="container"], form, table').should('exist');
        });
    }

    // These two pages used to log their whole form state on every render
    for (const [model, column, value, url] of [
        ['WholesaleOut', 'doc_num', 'WO-OWN', id => `/wholesale-out/${id}/edit`],
        ['Backorder', 'status', '0', id => `/backorder/${id}/edit`],
    ]) {
        it(`does not log to the console on the ${model} edit page`, () => {
            cy.idOf(model, column, value).then(id => {
                cy.visit(url(id), { onBeforeLoad: win => cy.spy(win.console, 'log').as('consoleLog') });
                cy.get('#app').should('not.be.empty');
                cy.get('@consoleLog').should('not.have.been.called');
            });
        });
    }

    for (const [model, column, value, url] of editPages) {
        it(`renders the ${model} ${url(':id')} page`, () => {
            cy.idOf(model, column, value).then(id => {
                expect(id, `${model} fixture`).to.be.a('number');
                cy.visit(url(id));
                cy.get('#app').should('not.be.empty');
            });
        });
    }
});
