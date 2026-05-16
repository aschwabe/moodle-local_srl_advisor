/* eslint-env node */
'use strict';

/**
 * Stand-alone Grunt config for local_srl_advisor.
 *
 * Lints AMD source and minifies to amd/build/*.min.js with source maps.
 * Built artifacts MUST be committed and shipped inside the plugin zip —
 * target Moodle institutions do not run Node or Grunt.
 *
 * Usage:
 *   npm ci             # install devDependencies
 *   npx grunt amd      # lint + build once
 *   npx grunt watch    # rebuild on save during development
 *
 * Output: amd/build/<module>.min.js + amd/build/<module>.min.js.map
 */
module.exports = function(grunt) {

    grunt.initConfig({
        eslint: {
            amd: {
                src: ['amd/src/**/*.js']
            },
            options: {
                overrideConfigFile: '.eslintrc.json'
            }
        },
        terser: {
            options: {
                sourceMap: true,
                format: {comments: false}
            },
            amd: {
                files: [{
                    expand: true,
                    cwd: 'amd/src',
                    src: ['**/*.js'],
                    dest: 'amd/build',
                    ext: '.min.js'
                }]
            }
        },
        watch: {
            amd: {
                files: ['amd/src/**/*.js'],
                tasks: ['eslint:amd', 'terser:amd'],
                options: {spawn: false}
            }
        }
    });

    grunt.loadNpmTasks('grunt-eslint');
    grunt.loadNpmTasks('grunt-terser');
    grunt.loadNpmTasks('grunt-contrib-watch');

    grunt.registerTask('amd', ['eslint:amd', 'terser:amd']);
    grunt.registerTask('default', ['amd']);
};
