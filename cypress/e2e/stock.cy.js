// Reordering and deleting the stocks of a record from the record edit page.
// Reordering is a PATCH now, and both actions are limited to the user's areas.
describe('Record stocks', () => {
    const stockAreaOrder = () =>
        cy.php("App\\Models\\Stock::ordered()->with('area')->get()->pluck('area.name')->all()");

    const dragRow = (from, to) => {
        const dataTransfer = new DataTransfer();
        cy.get('tbody tr').eq(from).trigger('dragstart', { dataTransfer });
        cy.get('tbody tr').eq(to).trigger('dragover', { dataTransfer }).trigger('drop', { dataTransfer });
    };

    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('operator@example.com');
        cy.idOf('Record', 'title', 'Cypress Record').then(id => cy.visit(`/record/${id}/edit`));
        cy.get('tbody tr').should('have.length.at.least', 4);
    });

    it('reorders two own stocks by dragging them', () => {
        stockAreaOrder().should('deep.equal', ['Own Warehouse Area', 'Own Back Area', 'Own Store Area', 'Foreign Warehouse Area']);
        cy.intercept('PATCH', '**/stock/*/*').as('swap');

        dragRow(0, 1);

        cy.wait('@swap').its('response.statusCode').should('be.oneOf', [302, 303]);
        stockAreaOrder().should('deep.equal', ['Own Back Area', 'Own Warehouse Area', 'Own Store Area', 'Foreign Warehouse Area']);
    });

    it('does not reorder an own stock with a foreign one', () => {
        cy.intercept('PATCH', '**/stock/*/*').as('swap');

        dragRow(0, 3);

        cy.wait('@swap').its('response.statusCode').should('eq', 403);
        stockAreaOrder().should('deep.equal', ['Own Warehouse Area', 'Own Back Area', 'Own Store Area', 'Foreign Warehouse Area']);
    });

    it('deletes an own stock and keeps the foreign one out of reach', () => {
        // The foreign stock row can't be deleted from the UI
        cy.get('tbody tr').eq(3).find('button.bg-destructive, button[class*="destructive"]').should('be.disabled');

        cy.get('tbody tr').eq(1).find('button[class*="destructive"]').click();
        cy.intercept('DELETE', '**/stock/*').as('delete');
        cy.contains('button', 'Elimina').click();
        cy.wait('@delete');

        cy.php("App\\Models\\Stock::whereHas('area', fn ($q) => $q->where('name', 'Own Back Area'))->exists()").should('eq', false);
        cy.php("App\\Models\\Stock::whereHas('area', fn ($q) => $q->where('name', 'Foreign Warehouse Area'))->exists()").should('eq', true);
    });
});
