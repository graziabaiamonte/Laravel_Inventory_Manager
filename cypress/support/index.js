/// <reference types="./" />

// Laravel helpers from laracasts/cypress: cy.login(), cy.create(), cy.php(), cy.artisan()...
// They call the /__cypress__ routes, which only exist on the cypress-app instance
// (CYPRESS_ROUTES=true, see config/app.php).
import './laravel-commands';
import './laravel-routes';
import './assertions';
import './app-commands';

before(() => {
    // The specs reset the database, so refuse to run against anything but a testing database
    cy.php("config('database.connections.mysql.database')").then(database => {
        if (!String(database).startsWith('testing')) {
            throw new Error(`Refusing to run the end to end tests against the "${database}" database`);
        }
    });

    cy.refreshRoutes();
});

// Any console error logged by a page fails the test that loaded it
let consoleErrors = [];

Cypress.on('window:before:load', win => {
    const original = win.console.error;
    win.console.error = (...args) => {
        consoleErrors.push(args.map(String).join(' '));
        original.apply(win.console, args);
    };
});

beforeEach(() => {
    consoleErrors = [];
});

afterEach(() => {
    expect(consoleErrors, 'console errors logged by the page').to.deep.equal([]);
});
