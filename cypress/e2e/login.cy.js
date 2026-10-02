// Login form. Turnstile is skipped for the Cypress user agent in the local and testing
// environments (AuthenticatedSessionController), so this covers the form itself.
describe('Login', () => {
    beforeEach(() => {
        cy.resetDatabase();
        cy.visit('/login');
    });

    it('shows the login form', () => {
        cy.get('input[name="email"]').should('exist');
        cy.get('input[name="password"]').should('exist');
        cy.contains('form button', 'Accedi').should('exist');
    });

    it('rejects invalid credentials', () => {
        cy.get('input[name="email"]').type('invalidemail@example.com');
        cy.get('input[name="password"]').type('invalidpassword');
        cy.contains('form button', 'Accedi').click();

        cy.get('.text-red-600').should('be.visible');
        cy.location('pathname').should('eq', '/login');
    });

    it('logs in with valid credentials', () => {
        cy.intercept('GET', '**/dashboard').as('dashboard');

        cy.get('input[name="email"]').type('admin@example.com');
        cy.get('input[name="password"]').type('password');
        cy.contains('form button', 'Accedi').click();

        cy.location('pathname').should('eq', '/dashboard');
        cy.wait('@dashboard').its('response.statusCode').should('eq', 200);
    });
});
