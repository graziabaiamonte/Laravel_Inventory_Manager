// Bulk deleting locations: a location with areas is skipped, and a location that was
// already deleted meanwhile (e.g. from another tab) must not break the request.
describe('Location bulk delete', () => {
    const rowCheckbox = name => cy.contains('tr', name).find('input[type="checkbox"]');
    const isTrashed = name => cy.php(`App\\Models\\Location::withTrashed()->where('name', '${name}')->first()?->trashed()`);

    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('admin@example.com');
    });

    it('skips missing and non empty locations', () => {
        cy.php("App\\Models\\Location::factory()->create(['name' => 'Gone Location', 'type' => 'store', 'status' => 1])->id");
        cy.visit('/location');

        rowCheckbox('Empty Location').check();
        rowCheckbox('Gone Location').check();
        rowCheckbox('Own Warehouse').check();

        // Deleted elsewhere after being selected here
        cy.php("App\\Models\\Location::where('name', 'Gone Location')->forceDelete()");

        cy.contains('button', 'Delete').click();
        cy.contains('button', 'Elimina').click();

        cy.contains('Location eliminati con successo').should('be.visible');
        isTrashed('Empty Location').should('eq', true);
        isTrashed('Own Warehouse').should('eq', false);
    });
});
