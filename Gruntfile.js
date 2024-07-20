module.exports = function(grunt) {
    // Project configuration
    grunt.initConfig({
        // Configuration for the stylelint task
        stylelint: {
            options: {
                configFile: '.stylelintrc', // Path to your stylelint configuration file
            },
            scss: {
                src: ['vue3/scss/*.scss'] // Specify the SCSS files to lint
            }
        },
        // Other task configurations
    });

    // Load the plugin that provides the "stylelint" task
    grunt.loadNpmTasks('grunt-stylelint');

    // Default task(s)
    grunt.registerTask('default', ['stylelint']);
};
