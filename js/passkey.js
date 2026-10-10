// Passkey / fingerprint / screen-lock helpers (WebAuthn). Talks to passkey_api.php.
(function () {
    'use strict';

    function b64uToBuf(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }
    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function api(action, payload) {
        var body = Object.assign({ action: action }, payload || {});
        return fetch('passkey_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (window.CD && window.CD.csrf) || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    window.cdPasskey = {
        supported: function () {
            return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
        },

        login: function () {
            return api('login_options').then(function (opt) {
                if (!opt.ok) throw new Error(opt.error || 'Failed');
                var o = opt.options;
                return navigator.credentials.get({
                    publicKey: {
                        challenge: b64uToBuf(o.challenge),
                        rpId: o.rpId,
                        timeout: 60000,
                        userVerification: 'required',
                        allowCredentials: []
                    }
                });
            }).then(function (cred) {
                if (!cred) throw new Error('Cancelled');
                var r = cred.response;
                return api('login_verify', {
                    id: bufToB64u(cred.rawId),
                    clientDataJSON: bufToB64u(r.clientDataJSON),
                    authenticatorData: bufToB64u(r.authenticatorData),
                    signature: bufToB64u(r.signature)
                });
            });
        },

        register: function (label) {
            return api('reg_options').then(function (opt) {
                if (!opt.ok) throw new Error(opt.error || 'Failed');
                var o = opt.options;
                return navigator.credentials.create({
                    publicKey: {
                        rp: { name: o.rpName, id: o.rpId },
                        user: { id: b64uToBuf(o.userId), name: o.userName, displayName: o.displayName },
                        challenge: b64uToBuf(o.challenge),
                        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                        timeout: 60000,
                        attestation: 'none',
                        authenticatorSelection: { residentKey: 'required', requireResidentKey: true, userVerification: 'required' },
                        excludeCredentials: (o.exclude || []).map(function (id) { return { type: 'public-key', id: b64uToBuf(id) }; })
                    }
                });
            }).then(function (cred) {
                if (!cred) throw new Error('Cancelled');
                return api('reg_verify', {
                    label: label || '',
                    clientDataJSON: bufToB64u(cred.response.clientDataJSON),
                    attestationObject: bufToB64u(cred.response.attestationObject)
                });
            });
        }
    };
})();
