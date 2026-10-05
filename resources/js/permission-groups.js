/**
 * "Select all in this group" for the role permission editor.
 *
 * Presentation only. Whatever the browser submits is validated server-side
 * against the permissions that exist, so this cannot grant anything.
 */
export function initPermissionGroupToggles(root = document) {
    root.querySelectorAll('[data-permission-group-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const group = button.dataset.permissionGroupToggle;
            const container = root.querySelector(`[data-permission-group="${group}"]`);

            if (!container) {
                return;
            }

            const boxes = [...container.querySelectorAll('input[type="checkbox"]:not([disabled])')];

            if (boxes.length === 0) {
                return;
            }

            // Toggle towards whichever state is not already universal.
            const shouldCheck = boxes.some((box) => !box.checked);
            boxes.forEach((box) => {
                box.checked = shouldCheck;
            });
        });
    });
}
