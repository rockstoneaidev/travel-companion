import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';

interface SocialAccount {
    provider: string;
    label: string;
    email: string | null;
    linked_at: string | null;
}

interface PasswordProps {
    hasPassword: boolean;
    socialAccounts: SocialAccount[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sign-in settings',
        href: '/settings/password',
    },
];

export default function Password({ hasPassword, socialAccounts }: PasswordProps) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sign-in settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={hasPassword ? 'Update password' : 'Set a password'}
                        description={
                            hasPassword
                                ? 'Ensure your account is using a long, random password to stay secure'
                                : 'You signed up with Google and have no password yet. Set one to also log in with your email address.'
                        }
                    />

                    <form onSubmit={updatePassword} className="space-y-6">
                        {/* A Google-created account has no current password to confirm; the
                            backend rejects the field outright for these users. */}
                        {hasPassword && (
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">Current password</Label>

                                <Input
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    type="password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Current password"
                                />

                                <InputError message={errors.current_password} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="password">{hasPassword ? 'New password' : 'Password'}</Label>

                            <Input
                                id="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder={hasPassword ? 'New password' : 'Password'}
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm password</Label>

                            <Input
                                id="password_confirmation"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder="Confirm password"
                            />

                            <InputError message={errors.password_confirmation} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save password</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <Separator />

                <ConnectedAccounts accounts={socialAccounts} hasPassword={hasPassword} />
            </SettingsLayout>
        </AppLayout>
    );
}

function ConnectedAccounts({ accounts, hasPassword }: { accounts: SocialAccount[]; hasPassword: boolean }) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const { delete: destroy, processing } = useForm();

    const google = accounts.find((account) => account.provider === 'google');

    // Removing the only way in would lock the user out — the backend refuses, so
    // don't offer the button either.
    const isOnlyWayIn = !hasPassword && accounts.length === 1;

    const disconnect = (provider: string) => {
        destroy(route('social.destroy', { provider }), { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            <HeadingSmall title="Connected accounts" description="Sign in with a third-party account instead of your password" />

            <InputError message={errors.provider} />

            <div className="flex items-center justify-between rounded-lg border p-4">
                <div className="grid gap-1">
                    <p className="text-sm font-medium">Google</p>
                    <p className="text-muted-foreground text-sm">{google ? (google.email ?? 'Connected') : 'Not connected'}</p>
                </div>

                {google ? (
                    <Button variant="outline" disabled={processing || isOnlyWayIn} onClick={() => disconnect('google')}>
                        Disconnect
                    </Button>
                ) : (
                    <Button variant="outline" asChild>
                        {/* Off-site OAuth redirect — a plain anchor, not an Inertia link. */}
                        <a href={route('auth.google.redirect')}>Connect</a>
                    </Button>
                )}
            </div>

            {isOnlyWayIn && (
                <p className="text-muted-foreground text-sm">Set a password before disconnecting Google — it's currently your only way to sign in.</p>
            )}
        </div>
    );
}
