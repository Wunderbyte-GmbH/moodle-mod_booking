// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Vite configuration for building the Vue 3 components of mod_booking.
 *
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  build: {
    outDir: 'amd/build',
    emptyOutDir: true,
    lib: {
      entry: 'main.js',
      name: 'local_adele',
      fileName: () => 'main.js'
    },
    rollupOptions: {
      external: [
        'core/ajax',
        'core/str',
        'core/notification',
        'core/templates',
        'core/localstorage',
        'jquery',
      ],
      output: {
        format: 'iife',
        globals: {
          'core/ajax': 'core/ajax',
          'core/str': 'core/str',
          'core/notification': 'core/notification',
          'core/templates': 'core/templates',
          'core/localstorage': 'core/localstorage',
          'jquery': 'jQuery',
        }
      }
    }
  }
})