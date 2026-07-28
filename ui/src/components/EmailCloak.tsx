import { useState, useEffect, useRef } from 'react';

/**
 * EmailCloak renders an email address constructed client-side from
 * segmented string parts, so naive scrapers that grep for "@" or mailto:
 * patterns in raw HTML don't pick it up.
 *
 * Trade-offs vs raw <a href="mailto:...">:
 *   - Bots that execute JS will still see the address (any modern scraper
 *     doing headless browser rendering will).
 *   - Email link won't work if JS is disabled.
 *   - Search engines follow links for spam analysis less aggressively now
 *     (nofollow on mailto is the convention) — but a static string in HTML
 *     is the main signal they look for.
 *
 * For an Austrian imprint this is "good enough" against crawlers that
 * target tens of thousands of pages (this site isn't on that scale yet).
 * If the spam volume becomes a problem, the next step is to drop the
 * obfuscated email entirely and rely only on the contact form.
 */
interface EmailCloakProps {
  /** Local part, e.g. "flightnoisetracker". */
  local: string;
  /** Domain part, e.g. "kersch.at". */
  domain: string;
  /** Optional display label override (defaults to local@domain). */
  label?: string;
  /** Set true to render as plain text instead of mailto: link. */
  asText?: boolean;
  className?: string;
}

export default function EmailCloak({
  local,
  domain,
  label,
  asText,
  className,
}: EmailCloakProps) {
  // Build the email address only after mount, so the static HTML/JS payload
  // never contains the full "local@domain" string. Bots that diff DOM vs
  // source HTML will see the difference.
  const [email, setEmail] = useState<string | null>(null);
  const assembledRef = useRef(false);

  useEffect(() => {
    if (assembledRef.current) return;
    assembledRef.current = true;
    // Rot13'd local part obfuscation (also done in HTML for non-JS readers
    // is overkill — we already rely on JS for obfuscation).
    setEmail(`${local}@${domain}`);
  }, [local, domain]);

  const display = label ?? email ?? '';

  if (!email) {
    // Render placeholder of the same length so layout doesn't jump.
    return <span className={className}>••••••••••••••••</span>;
  }

  if (asText) {
    return <span className={className}>{display}</span>;
  }

  return (
    <a
      href={`mailto:${email}`}
      className={className ?? 'text-blue-600 dark:text-blue-400 hover:underline'}
      onClick={(e) => {
        // Belt-and-braces: refuse to navigate if JS building the address
        // failed for some reason. (Should never happen in practice.)
        if (!email) e.preventDefault();
      }}
    >
      {display}
    </a>
  );
}
