// The page size requested by the index tables is capped, so a crafted URL can't load a whole table
describe('Pagination', () => {
    const pageProps = () =>
        cy
            .get('#app')
            .invoke('attr', 'data-page')
            .then(page => JSON.parse(page).props);

    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('admin@example.com');
    });

    it('caps per_page at 500', () => {
        cy.visit('/artist?per_page=1000000');
        pageProps().its('artists.per_page').should('eq', 500);
    });

    it('keeps a normal per_page', () => {
        cy.visit('/artist?per_page=20');
        pageProps().its('artists.per_page').should('eq', 20);
    });
});
