// Mirrors of PHP enums that cross the wire (docs/conventions/02-enums.md).
// Parity with the PHP cases is asserted by tests/Feature/EnumParityTest.php.

export const ROLES = ['admin', 'superadmin'] as const;
export type Role = (typeof ROLES)[number];

export const PERMISSIONS = ['admin_access', 'ops_view', 'users_view', 'users_manage_roles', 'activity_view'] as const;
export type Permission = (typeof PERMISSIONS)[number];
