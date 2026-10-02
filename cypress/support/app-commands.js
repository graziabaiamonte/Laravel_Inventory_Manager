// Project specific helpers on top of laracasts/cypress

// Empties every table and seeds the deterministic fixture from database/seeders/CypressSeeder.php.
// The schema is migrated once when the cypress-app container starts: migrate:fresh on every
// spec would take longer than the request timeout.
const truncateAllTables = `
    $database = DB::getDatabaseName();
    if (! str_starts_with($database, 'testing')) {
        throw new RuntimeException("Refusing to truncate the {$database} database");
    }
    Schema::disableForeignKeyConstraints();
    foreach (Schema::getTableListing() as $table) {
        if ($table !== 'migrations') {
            DB::table($table)->truncate();
        }
    }
    Schema::enableForeignKeyConstraints();
    return $database;
`;

Cypress.Commands.add('resetDatabase', () => {
    cy.php(truncateAllTables);
    cy.seed('CypressSeeder');
});

// Logs in as one of the users created by CypressSeeder
Cypress.Commands.add('loginAs', email => cy.login({ email }));

// Id of a model found by a column value, e.g. cy.idOf('WholesaleOut', 'doc_num', 'WO-OWN')
Cypress.Commands.add('idOf', (model, column, value) =>
    cy.php(`App\\Models\\${model}::where('${column}', '${value}')->value('id')`),
);

// Picks an option in one of the headlessui Combo components, found by its label
Cypress.Commands.add('selectCombo', (label, option) => {
    cy.contains('label', label).parent().find('input').first().click();
    cy.contains('[role="option"]', option).click();
});
