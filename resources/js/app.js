import * as bootstrap from 'bootstrap';
import { initCustomerDailyEntry } from './customer-daily-entry.js';
import { initFundingEditors } from './funding-editor.js';
import { initPermissionGroupToggles } from './permission-groups.js';

// Expose Bootstrap's JS API so Blade views and feature modules can create
// modals, offcanvas sidebars, toasts and tooltips without re-importing it.
window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    initPermissionGroupToggles();
    initFundingEditors();
    initCustomerDailyEntry();
});
