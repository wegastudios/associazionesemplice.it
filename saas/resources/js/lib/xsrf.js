// Nome del cookie con il token XSRF: lo decide il server (`__Host-XSRF-TOKEN` in HTTPS, `XSRF-TOKEN` in locale su http) e lo
// scrive nella pagina (meta «xsrf-cookie», vedi app.blade.php). Senza il meta si resta sul nome di prima.
export const xsrfCookieName = () => document.querySelector('meta[name="xsrf-cookie"]')?.content || 'XSRF-TOKEN';

// Il valore del cookie (cifrato, da rimandare nell'intestazione X-XSRF-TOKEN), o stringa vuota.
export const xsrfToken = () => {
    const prefix = `${xsrfCookieName()}=`;

    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith(prefix))?.slice(prefix.length) ?? '');
};
