module.exports = {
    e2e: {
        // Locally the specs run against the cypress-app compose service, CI passes CYPRESS_BASE_URL
        baseUrl: 'http://cypress-app:8001',
        supportFile: 'cypress/support/index.js',
        specPattern: 'cypress/e2e/**/*.cy.js',
        video: false,
        defaultCommandTimeout: 10000,
    },
};
