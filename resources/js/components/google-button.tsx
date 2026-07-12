import { Button } from '@/components/ui/button';

// Google's mark, inlined: their brand guidelines require the four-colour "G"
// (a monochrome icon is not permitted), and a remote asset would be a request
// to google.com on every render of the login screen.
function GoogleMark({ className }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path
                fill="#4285F4"
                d="M23.52 12.27c0-.82-.07-1.6-.21-2.36H12v4.46h6.46a5.52 5.52 0 0 1-2.4 3.62v3.01h3.88c2.27-2.09 3.58-5.17 3.58-8.73Z"
            />
            <path
                fill="#34A853"
                d="M12 24c3.24 0 5.96-1.08 7.94-2.91l-3.88-3.01c-1.08.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.73-4.95H1.26v3.09A11.995 11.995 0 0 0 12 24Z"
            />
            <path fill="#FBBC05" d="M5.27 14.28a7.2 7.2 0 0 1 0-4.56V6.63H1.26a12.01 12.01 0 0 0 0 10.74l4.01-3.09Z" />
            <path
                fill="#EA4335"
                d="M12 4.77c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.69 1.26 6.63l4.01 3.09C6.22 6.88 8.87 4.77 12 4.77Z"
            />
        </svg>
    );
}

/**
 * Full-width "Continue with Google". A plain <a>, not an Inertia <Link>: the
 * target is an OAuth redirect off-site, and Inertia would try to XHR it.
 */
export default function GoogleButton({ label = 'Continue with Google', tabIndex }: { label?: string; tabIndex?: number }) {
    return (
        <Button variant="outline" className="w-full" asChild tabIndex={tabIndex}>
            <a href={route('auth.google.redirect')}>
                <GoogleMark className="h-4 w-4" />
                {label}
            </a>
        </Button>
    );
}
