// Formats, labels and artists first created by a wholesale in import must be active,
// otherwise they are missing from the record create and edit dropdowns.
describe('Wholesale in import', () => {
    const spreadsheet = 'storage/app/cypress/new-lookups.xlsx';

    // Builds the import spreadsheet on the server, the repository is mounted in both containers
    const writeSpreadsheet = () =>
        cy.php(`
            $headers = ['cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q', 'price', 'own_warehouse_area'];
            $row = ['CY-NEW', 'New Cypress Artist', 'New Cypress Title', 'New Cypress Format', 'New Cypress Label', '8098765432109', 2, 10, 2];
            $sheet = new PhpOffice\\PhpSpreadsheet\\Spreadsheet();
            $sheet->getActiveSheet()->fromArray([$headers, $row]);
            $sheet->getActiveSheet()->getCell('F2')->setValueExplicit('8098765432109', PhpOffice\\PhpSpreadsheet\\Cell\\DataType::TYPE_STRING);
            File::ensureDirectoryExists(storage_path('app/cypress'));
            (new PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx($sheet))->save(storage_path('app/cypress/new-lookups.xlsx'));
            return true;
        `);

    const lookupStatus = (model, name) => cy.php(`App\\Models\\${model}::where('name', '${name}')->value('status')`);

    beforeEach(() => {
        cy.resetDatabase();
        writeSpreadsheet();
        cy.loginAs('admin@example.com');
    });

    it('creates the new format, label and artist as active and offers them on the record form', () => {
        cy.visit('/wholesale-in/create');
        cy.selectCombo('Fornitore', 'Cypress Supplier');
        cy.selectCombo('Area di carico', 'Own Warehouse Area');
        cy.contains('label', 'Numero Documento').parent().find('input').type('WI-IMPORT');

        cy.get('input[type="file"]').selectFile(spreadsheet, { force: true });
        cy.contains('button', 'Carica').click();

        // The first submit parses the spreadsheet into the preview, the second one saves it
        cy.get('tbody input').filter((_, input) => input.value === 'New Cypress Title').should('have.length', 1);
        cy.contains('button', 'Carica').click();
        cy.location('pathname').should('eq', '/wholesale-in');

        lookupStatus('Format', 'New Cypress Format').should('eq', 1);
        lookupStatus('Label', 'New Cypress Label').should('eq', 1);
        lookupStatus('Artist', 'New Cypress Artist').should('eq', 1);

        cy.visit('/record/create');
        cy.contains('label', 'Formato').parent().find('input').first().click();
        cy.contains('[role="option"]', 'New Cypress Format').should('exist');
    });
});
