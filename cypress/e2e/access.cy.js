// Location scoping: an operator can only open, export and print the wholesale
// documents of the areas connected to its locations.
describe('Wholesale document access', () => {
    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('operator@example.com');
    });

    it('lists only the own wholesale outs and ins', () => {
        cy.visit('/wholesale-out');
        cy.contains('WO-OWN').should('be.visible');
        cy.contains('WO-FOREIGN').should('not.exist');

        cy.visit('/wholesale-in');
        cy.contains('WI-OWN').should('be.visible');
        cy.contains('WI-FOREIGN').should('not.exist');
    });

    it('opens the own wholesale out', () => {
        cy.idOf('WholesaleOut', 'doc_num', 'WO-OWN').then(id => {
            cy.visit(`/wholesale-out/${id}/edit`);
            cy.get('input[value="WO-OWN"]').should('exist');
        });
    });

    it('forbids opening or exporting a foreign wholesale out', () => {
        cy.idOf('WholesaleOut', 'doc_num', 'WO-FOREIGN').then(id => {
            for (const url of [`/wholesale-out/${id}/edit`, `/wholesale-out/${id}/export`]) {
                cy.request({ url, failOnStatusCode: false }).its('status').should('eq', 403);
            }
        });
    });

    it('forbids opening, exporting or printing a foreign wholesale in', () => {
        cy.idOf('WholesaleIn', 'doc_num', 'WI-FOREIGN').then(id => {
            for (const url of [`/wholesale-in/${id}/edit`, `/wholesale-in/${id}/export`, `/wholesale-in/${id}/barcodes`]) {
                cy.request({ url, failOnStatusCode: false }).its('status').should('eq', 403);
            }
        });
    });
});
