/*
 * WebAuthn ceremonies, by hand.
 *
 * Done directly rather than through a browser library: it is a handful of lines, and one fewer
 * dependency to audit in something that guards a control plane. The only real work is base64url
 * conversion, because WebAuthn wants ArrayBuffers and JSON carries strings.
 */

function base64UrlToBuffer(value) {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded.padEnd(padded.length + ((4 - (padded.length % 4)) % 4), '='));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: body === undefined ? '{}' : JSON.stringify(body),
    });

    const text = await response.text();
    const json = text ? JSON.parse(text) : {};

    if (!response.ok) {
        throw new Error(json.error ?? `Request failed (${response.status})`);
    }

    return json;
}

function setMessage(element, text, isError) {
    if (!element) {
        return;
    }

    element.textContent = text;
    element.dataset.state = isError ? 'error' : 'ok';
}

/**
 * Register a new passkey against the signed-in account.
 */
async function registerPasskey(button) {
    const message = document.querySelector('[data-passkey-message]');
    const nameField = document.querySelector('[data-passkey-name]');

    if (!window.PublicKeyCredential) {
        setMessage(message, 'This browser does not support passkeys.', true);

        return;
    }

    button.disabled = true;
    setMessage(message, 'Follow your browser or device prompt…', false);

    try {
        const options = await postJson(button.dataset.optionsUrl);

        options.challenge = base64UrlToBuffer(options.challenge);
        options.user.id = base64UrlToBuffer(options.user.id);

        (options.excludeCredentials ?? []).forEach((credential) => {
            credential.id = base64UrlToBuffer(credential.id);
        });

        const credential = await navigator.credentials.create({ publicKey: options });

        await postJson(button.dataset.registerUrl, {
            name: nameField?.value ?? '',
            credential: {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64Url(credential.response.attestationObject),
                },
            },
        });

        // Reloaded rather than patched into the DOM: the account page is rendered server-side, and
        // the list of passkeys is exactly the sort of thing that should come from the source of truth.
        window.location.reload();
    } catch (error) {
        // A cancelled prompt is not a failure worth shouting about.
        const cancelled = error.name === 'NotAllowedError' || error.name === 'AbortError';

        setMessage(message, cancelled ? 'Cancelled.' : error.message, !cancelled);
        button.disabled = false;
    }
}

/**
 * Satisfy the second-factor challenge with a passkey.
 */
async function assertPasskey(button) {
    const message = document.querySelector('[data-passkey-message]');

    if (!window.PublicKeyCredential) {
        setMessage(message, 'This browser does not support passkeys. Use a code instead.', true);

        return;
    }

    button.disabled = true;
    setMessage(message, 'Follow your browser or device prompt…', false);

    try {
        const options = await postJson(button.dataset.optionsUrl);

        options.challenge = base64UrlToBuffer(options.challenge);

        (options.allowCredentials ?? []).forEach((credential) => {
            credential.id = base64UrlToBuffer(credential.id);
        });

        const assertion = await navigator.credentials.get({ publicKey: options });

        const result = await postJson(button.dataset.assertUrl, {
            credential: {
                id: assertion.id,
                rawId: bufferToBase64Url(assertion.rawId),
                type: assertion.type,
                response: {
                    authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                    clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                    signature: bufferToBase64Url(assertion.response.signature),
                    userHandle: assertion.response.userHandle
                        ? bufferToBase64Url(assertion.response.userHandle)
                        : null,
                },
            },
        });

        window.location.href = result.redirect ?? '/sites';
    } catch (error) {
        const cancelled = error.name === 'NotAllowedError' || error.name === 'AbortError';

        setMessage(
            message,
            cancelled ? 'Cancelled. You can still use a code.' : 'That passkey was not accepted.',
            !cancelled,
        );
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-passkey-register]')
        ?.addEventListener('click', (event) => registerPasskey(event.currentTarget));

    document.querySelector('[data-passkey-assert]')
        ?.addEventListener('click', (event) => assertPasskey(event.currentTarget));
});
