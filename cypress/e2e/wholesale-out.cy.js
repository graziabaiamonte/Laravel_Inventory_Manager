// The wholesale out and backorder edit pages had their import and save handlers cleaned
// up (debug logging removed), so exercise them end to end rather than just rendering them.
describe('Wholesale out and backorder editing', () => {
    // Builds the import spreadsheet on the server, the repository is mounted in both containers
    const writeSpreadsheet = () =>
        cy.php(`
            $headers = ['cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'order_amount', 'price', 'discount', 'iva', 'own_warehouse_area'];
            $row = ['CY-001', 'Cypress Artist', 'Cypress Record', 'LP', 'Cypress Label', '8012345678901', 2, 10, 0, 22, 'x'];
            $sheet = new PhpOffice\\PhpSpreadsheet\\Spreadsheet();
            $sheet->getActiveSheet()->fromArray([$headers, $row]);
            $sheet->getActiveSheet()->getCell('F2')->setValueExplicit('8012345678901', PhpOffice\\PhpSpreadsheet\\Cell\\DataType::TYPE_STRING);
            File::ensureDirectoryExists(storage_path('app/cypress'));
            (new PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx($sheet))->save(storage_path('app/cypress/wholesale-out.xlsx'));
            return true;
        `);

    beforeEach(() => {
        cy.resetDatabase();
        cy.loginAs('admin@example.com');
    });

    it('imports a spreadsheet into a draft wholesale out and saves it', () => {
        writeSpreadsheet();
        cy.idOf('WholesaleOut', 'doc_num', 'WO-OWN').then(id => {
            cy.visit(`/wholesale-out/${id}/edit`);

            cy.get('input[type="file"][accept=".xlsx"]').first().selectFile('storage/app/cypress/wholesale-out.xlsx', { force: true });
            cy.contains('button', 'Carica file').click();

            // The imported line replaces the (empty) records of the draft
            cy.contains('td', 'CY-001').should('exist');
            cy.intercept({ method: /PUT|PATCH|POST/, pathname: `/wholesale-out/${id}` }).as('update');
            cy.contains('button', 'Aggiorna Scarico').click();
            cy.wait('@update');

            cy.php(`App\\Models\\WholesaleOutRecord::where('wholesale_out_id', ${id})->sum('quantity')`).then(Number).should('eq', 2);
        });
    });

    it('saves a backorder without changes', () => {
        cy.idOf('Backorder', 'status', '0').then(id => {
            cy.intercept('POST', `**/backorder/${id}`).as('save');
            cy.visit(`/backorder/${id}/edit`);

            cy.contains('button', 'Salva Modifiche').click();

            cy.wait('@save').its('response.statusCode').should('be.oneOf', [302, 303]);
        });
    });
});
