import jquery from 'jquery';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

let $;
let Controller;
let installControls;
let installFacets;
let installView;

beforeAll(async () => {
  $ = jquery;
  window.jQuery = $;
  window.eitConfig = {
    restUrl: '/wp-json/eit/v1/filter',
	collectionRestUrl: '/wp-json/eit/v1/collections',
    i18n: { error: 'Current results remain visible.' },
  };
  ({ Controller } = await import('../../assets/src/frontend/controller.js'));
  ({ installControls } = await import('../../assets/src/frontend/controls.js'));
	({ installFacets } = await import('../../assets/src/frontend/facets.js'));
  ({ installView } = await import('../../assets/src/frontend/view.js'));
  installControls(Controller);
	installFacets(Controller);
  installView(Controller);
});

beforeEach(() => {
	vi.restoreAllMocks();
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

	it('queries a published Collection and applies compiled facet availability', () => {
		document.body.innerHTML = `
			<div class="eit-filter-controller"
				data-eit-config='{"provider":"collection","collectionId":"collection-id","collectionFacetIds":["status-id"],"autoApply":false,"perPage":24,"resultText":"{count} results"}'
				data-eit-filters='[{"id":"status-id","label":"Status","type":"checkbox","key":"status-id","compare":"in"},{"id":"quantity-id","label":"Quantity","type":"range","key":"quantity-id","compare":"between","rangeBounded":false}]'>
				<form class="eit-filter-controller__form">
					<div data-eit-filter-group="status-id" data-eit-filter-label="Status" data-eit-compare="in">
						<div data-eit-options><label class="eit-option"><input type="checkbox" checked value="open" data-eit-control data-eit-type="checkbox" data-eit-key="status-id"><span class="eit-option__label">Open</span></label></div>
					</div>
					<div data-eit-filter-group="quantity-id" data-eit-filter-label="Quantity" data-eit-compare="between">
						<div class="eit-range" data-eit-control data-eit-type="range" data-eit-key="quantity-id"><input value="" data-eit-range-min><input value="" data-eit-range-max></div>
					</div>
				</form>
				<section data-eit-collection-results></section>
				<div data-eit-result-count></div><div data-eit-active-filters></div><div data-eit-empty hidden></div>
				<div data-eit-error hidden tabindex="-1"></div><div data-eit-status></div><div data-eit-pagination></div>
			</div>`;
		const requests = [];
		vi.spyOn($, 'ajax').mockImplementation((options) => {
			const request = deferredRequest();
			request.options = options;
			requests.push(request);
			return request;
		});

		new Controller(document.querySelector('.eit-filter-controller'));
		expect(requests[0].options.url).toBe('/wp-json/eit/v1/collections/collection-id/query');
		expect(JSON.parse(requests[0].options.data)).toMatchObject({
			filters: [{ field_id: 'status-id', operator: 'in', value: ['open'] }],
			facets: ['status-id'],
		});

		requests[0].resolveWith({
			html: '<div data-eit-collection-items><article>Open item</article></div>',
			pagination: { total: 1, page: 1, pages: 1, per_page: 24 },
			facets: [{ field_id: 'status-id', values: [
				{ value: 'open', label: 'Open', count: 1, available: true },
				{ value: 'closed', label: 'Closed', count: 0, available: false },
			] }],
		});
		expect(document.querySelector('[data-eit-collection-results]').textContent).toContain('Open item');
		expect(document.querySelector('[value="closed"]').disabled).toBe(true);
		expect(document.querySelector('[data-eit-result-count]').textContent).toBe('1 results');
	});
});
