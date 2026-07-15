import jquery from 'jquery';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

let $;
let Controller;
let installControls;
let installView;

beforeAll(async () => {
  $ = jquery;
  window.jQuery = $;
  window.eitConfig = {
    restUrl: '/wp-json/eit/v1/filter',
    i18n: { error: 'Current results remain visible.' },
  };
  ({ Controller } = await import('../../assets/src/frontend/controller.js'));
  ({ installControls } = await import('../../assets/src/frontend/controls.js'));
  ({ installView } = await import('../../assets/src/frontend/view.js'));
  installControls(Controller);
  installView(Controller);
});

beforeEach(() => {
  document.body.innerHTML = `
    <div class="eit-filter-controller"
      data-eit-config='{"targetSelector":"#listing","autoApply":false,"perPage":24,"resultText":"{count} results"}'
      data-eit-filters='[]'>
      <form class="eit-filter-controller__form"></form>
      <div data-eit-result-count></div>
      <div data-eit-active-filters></div>
      <div data-eit-empty hidden></div>
      <div data-eit-error hidden tabindex="-1"></div>
      <div data-eit-status></div>
      <div data-eit-pagination></div>
    </div>
    <div id="listing" data-eit-listing>
      <article data-eit-item data-eit-client-id="a">Alpha</article>
      <article data-eit-item data-eit-client-id="b">Beta</article>
    </div>`;
});

function deferredRequest() {
  const deferred = $.Deferred();
  const request = deferred.promise();
  request.readyState = 1;
  request.abort = vi.fn(() => { request.readyState = 4; });
  request.resolveWith = (response) => {
    request.readyState = 4;
    deferred.resolve(response);
  };
  request.rejectWith = (xhr, status = 'error') => {
    request.readyState = 4;
    deferred.reject(xhr, status);
  };
  return request;
}

describe('frontend request state machine', () => {
  it('aborts stale work, applies only the latest response, and preserves results on error', () => {
    const requests = [];
    vi.spyOn($, 'ajax').mockImplementation(() => {
      const request = deferredRequest();
      requests.push(request);
      return request;
    });

    const controller = new Controller(document.querySelector('.eit-filter-controller'));
    expect(requests).toHaveLength(1);

    controller.apply(true, false);
    controller.apply(true, false);
    expect(requests[0].abort).toHaveBeenCalledOnce();
    expect(requests[1].abort).toHaveBeenCalledOnce();

    requests[1].resolveWith({ total: 99, page: 1, pages: 99, ids: ['b'] });
    expect(document.querySelector('[data-eit-result-count]').textContent).toBe('');

    requests[2].resolveWith({ total: 1, page: 1, pages: 1, ids: ['a'] });
    expect(document.querySelector('[data-eit-result-count]').textContent).toBe('1 results');
    expect(document.querySelector('[data-eit-client-id="a"]').hidden).toBe(false);
    expect(document.querySelector('[data-eit-client-id="b"]').hidden).toBe(true);
    expect(document.querySelector('.eit-filter-controller').getAttribute('aria-busy')).toBe('false');

    controller.apply(true, true);
    requests[3].rejectWith({ responseJSON: { message: 'Server rejected the request.' } });
    expect(document.querySelector('[data-eit-error]').textContent).toBe('Server rejected the request.');
    expect(document.activeElement).toBe(document.querySelector('[data-eit-error]'));
    expect(document.querySelector('[data-eit-client-id="a"]').hidden).toBe(false);
    expect(document.querySelector('[data-eit-client-id="b"]').hidden).toBe(true);
    expect(controller.lastResult.total).toBe(1);
  });
});
