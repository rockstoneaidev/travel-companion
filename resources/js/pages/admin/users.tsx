import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { type Role } from '@/types/enums';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin',
    },
    {
        title: 'Users',
        href: '/admin/users',
    },
];

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: Role[];
    createdAt: string;
}

interface RoleOption {
    value: Role;
    label: string;
}

function sameRoles(a: Role[], b: Role[]): boolean {
    return a.length === b.length && a.every((role) => b.includes(role));
}

function RoleEditor({ user, roleOptions }: { user: UserRow; roleOptions: RoleOption[] }) {
    const { data, setData, put, processing } = useForm<{ roles: Role[] }>({ roles: user.roles });

    const toggle = (role: Role, checked: boolean) => {
        setData('roles', checked ? [...data.roles, role] : data.roles.filter((r) => r !== role));
    };

    return (
        <div className="flex items-center gap-4">
            {roleOptions.map((option) => (
                <label key={option.value} className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={data.roles.includes(option.value)}
                        onCheckedChange={(checked) => toggle(option.value, checked === true)}
                        disabled={processing}
                    />
                    {option.label}
                </label>
            ))}
            {!sameRoles(data.roles, user.roles) && (
                <Button size="sm" disabled={processing} onClick={() => put(`/admin/users/${user.id}/roles`, { preserveScroll: true })}>
                    Save
                </Button>
            )}
        </div>
    );
}

export default function AdminUsers({ users, roleOptions }: { users: Paginated<UserRow>; roleOptions: RoleOption[] }) {
    const { auth, errors } = usePage<SharedData>().props;
    const canManageRoles = auth.permissions.includes('users_manage_roles');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {errors.roles && <div className="bg-destructive/10 text-destructive rounded-md px-4 py-2 text-sm">{errors.roles}</div>}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Email</th>
                                <th className="px-4 py-3 font-medium">Joined</th>
                                <th className="px-4 py-3 font-medium">Roles</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((user) => (
                                <tr key={user.id} className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b last:border-0">
                                    <td className="px-4 py-3 font-medium">{user.name}</td>
                                    <td className="text-muted-foreground px-4 py-3">{user.email}</td>
                                    <td className="text-muted-foreground px-4 py-3">{new Date(user.createdAt).toLocaleDateString()}</td>
                                    <td className="px-4 py-3">
                                        {canManageRoles && user.id !== auth.user.id ? (
                                            <RoleEditor user={user} roleOptions={roleOptions} />
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                {user.roles.length === 0 && <span className="text-muted-foreground">—</span>}
                                                {user.roles.map((role) => (
                                                    <Badge key={role} variant="secondary">
                                                        {role}
                                                    </Badge>
                                                ))}
                                                {canManageRoles && user.id === auth.user.id && (
                                                    <span className="text-muted-foreground text-xs">(you — ask another superadmin)</span>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <span>
                        {users.total} user{users.total === 1 ? '' : 's'}
                    </span>
                    <span className="flex gap-4">
                        {users.prev_page_url && (
                            <Link href={users.prev_page_url} className="hover:text-foreground" preserveScroll>
                                ← Previous
                            </Link>
                        )}
                        {users.next_page_url && (
                            <Link href={users.next_page_url} className="hover:text-foreground" preserveScroll>
                                Next →
                            </Link>
                        )}
                    </span>
                </div>
            </div>
        </AppLayout>
    );
}
