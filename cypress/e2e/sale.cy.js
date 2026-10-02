// Creating a sale from the UI: the line is scoped to the sale and its stock must belong to
// the same record, in an area the user can access. This checks the real form still passes
// that validation and moves the stock.
describe('Sale form', () => {
    const storeStockQuantity = () =>
        cy.php("App\\Models\\Stock::whereHas('area', fn ($q) => $q->where('name', 'Own Store Area'))->value('quantity')");

    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('operator@example.com');
    });

    it('creates and edits a sale, moving the store stock', () => {
        storeStockQuantity().should('eq', 20);

        cy.visit('/sale/create');
        cy.selectCombo('Location', 'Own Store');
        cy.selectCombo('Tipo', 'Negozio');
        // Pick the 15th of the month the date picker opens on
        cy.contains('label', 'Data').parent().find('input').click();
        cy.contains('button', /^15$/).click();
        cy.get('input[placeholder="Codice a barre / Id"]').type('8012345678901');
        cy.contains('td', 'Cypress Record').should('be.visible');
        cy.get('input[placeholder="Codice a barre / Id"]').type('{enter}');

        cy.contains('button', 'Crea').click();

        cy.location('pathname').should('match', /^\/sale\/\d+\/edit$/);
        cy.php('App\\Models\\SaleRecord::count()').should('eq', 1);
        storeStockQuantity().should('eq', 19);

        // Editing the line of the sale just created moves the stock by the difference
        cy.intercept({ method: /PUT|PATCH|POST/, pathname: /^\/sale\/\d+$/ }).as('update');
        cy.get('tbody input[type="number"]').first().type('{selectall}3');
        cy.contains('button', 'Aggiorna').click();
        cy.wait('@update');

        cy.php('App\\Models\\SaleRecord::value("quantity")').should('eq', 3);
        storeStockQuantity().should('eq', 17);
    });
});
