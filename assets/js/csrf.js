(function (global) {
    'use strict';

    // CSRF hidden input を内包するコンテナ要素を id から取得する。
    // ブラウザ外や id 未指定のケースでは null を返して呼び出し側で吸収する。
    function getContainer(containerId) {
        if (typeof document === 'undefined' || typeof containerId !== 'string' || containerId === '') {
            return null;
        }

        return document.getElementById(containerId);
    }

    // 画面に埋め込まれた hidden input から CSRF の scope/token を読み出す。
    // 値が揃っていない場合は空オブジェクトを返し、送信時に何も追加しない。
    function readCsrfFields(containerId) {
        const container = getContainer(containerId);
        if (!container) {
            return {};
        }

        const scope = container.querySelector('input[name="_csrf_scope"]');
        const token = container.querySelector('input[name="_csrf_token"]');

        if (!(scope instanceof HTMLInputElement) || !(token instanceof HTMLInputElement)) {
            return {};
        }

        if (scope.value === '' || token.value === '') {
            return {};
        }

        return {
            _csrf_scope: scope.value,
            _csrf_token: token.value,
        };
    }

    // サーバ応答に次回用の CSRF 値が含まれていれば、同じ hidden input を更新する。
    // 単回使用トークンを前提に、AJAX を連続実行しても次回送信が失敗しないようにする。
    function refreshCsrfFields(containerId, payload) {
        const container = getContainer(containerId);
        if (!container || !payload || typeof payload !== 'object') {
            return;
        }

        const scope = container.querySelector('input[name="_csrf_scope"]');
        const token = container.querySelector('input[name="_csrf_token"]');

        if (scope instanceof HTMLInputElement && typeof payload._csrf_scope === 'string') {
            scope.value = payload._csrf_scope;
        }
        if (token instanceof HTMLInputElement && typeof payload._csrf_token === 'string') {
            token.value = payload._csrf_token;
        }
    }

    // JSON POST の共通処理。
    // payload に現在の CSRF hidden 値を自動でマージして送信し、
    // 応答 JSON に新しい CSRF 値があれば hidden input 側へ反映する。
    async function postJson(url, payload, options) {
        const settings = options && typeof options === 'object' ? options : {};
        const headers = settings.headers && typeof settings.headers === 'object'
            ? { ...settings.headers }
            : {};

        if (!headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, {
            method: 'POST',
            credentials: settings.credentials || 'same-origin',
            headers,
            body: JSON.stringify({
                ...(payload && typeof payload === 'object' ? payload : {}),
                ...readCsrfFields(settings.csrfContainerId || ''),
            }),
        });

        const json = await response.json();
        refreshCsrfFields(settings.csrfContainerId || '', json);

        return json;
    }

    // 同じ CSRF コンテナや credentials を使い回すための軽量クライアントを作る。
    // 画面ごとに createJsonClient() を一度だけ呼んでおくと、個別処理は payload だけ渡せばよい。
    function createJsonClient(options) {
        const defaults = options && typeof options === 'object' ? { ...options } : {};

        return {
            postJson(url, payload, overrideOptions) {
                const merged = overrideOptions && typeof overrideOptions === 'object'
                    ? { ...defaults, ...overrideOptions }
                    : defaults;

                return postJson(url, payload, merged);
            },
        };
    }

    // グローバルへ公開する最小 API。
    // 他画面では ChandraCsrfFetch.createJsonClient(...) を入口として使う想定。
    global.ChandraCsrfFetch = {
        readCsrfFields,
        refreshCsrfFields,
        postJson,
        createJsonClient,
    };
})(window);
