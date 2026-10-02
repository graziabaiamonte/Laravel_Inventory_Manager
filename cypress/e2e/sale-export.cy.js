// The sales export runs in background jobs without an authenticated user, so it must
// receive the location scope explicitly: an operator exports only its own locations.
describe('Sales export', () => {
    beforeEach(() => {
        cy.resetDatabase();
    });

    const exportFromTheIndex = () => {
        cy.intercept({ method: 'GET', pathname: '/sales/export' }).as('startExport');
        cy.intercept('GET', '**/sales/export/*/status').as('exportStatus');

        cy.visit('/sale');
        cy.contains('button', 'Esporta Vendite').click();

        cy.wait('@startExport').its('response.body.success').should('eq', true);

        return cy.wait('@exportStatus').its('response.body.progress.total_records');
    };

    it('exports only the sales of the operator locations', () => {
        cy.loginAs('operator@example.com');

        exportFromTheIndex().should('eq', 2);
    });

    it('exports every sale for an admin', () => {
        cy.loginAs('admin@example.com');

        exportFromTheIndex().should('eq', 5);
    });
});
