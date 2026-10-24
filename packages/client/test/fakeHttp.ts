import type {
  HttpRequestConfig,
  HttpResponse,
  InertiaHttp,
} from "../src/index.js";

type Respond = (config: HttpRequestConfig) => HttpResponse | Promise<HttpResponse>;

/**
 * Stands in for Inertia's `http` object: its client runs the registered
 * handlers around `respond`, the way Inertia's own client does.
 */
export const createFakeHttp = (respond: Respond = () => ({ status: 200 })) => {
  const requestHandlers: Parameters<InertiaHttp["onRequest"]>[0][] = [];
  const responseHandlers: Parameters<InertiaHttp["onResponse"]>[0][] = [];
  const errorHandlers: Parameters<InertiaHttp["onError"]>[0][] = [];
  const sent: HttpRequestConfig[] = [];

  const http: InertiaHttp = {
    onRequest: (handler) => requestHandlers.push(handler),
    onResponse: (handler) => responseHandlers.push(handler),
    onError: (handler) => errorHandlers.push(handler),
    getClient: () => ({
      request: async (config) => {
        for (const handler of requestHandlers) {
          config = await handler(config);
        }
        sent.push(config);
        try {
          let response = await respond(config);
          for (const handler of responseHandlers) {
            response = await handler(response);
          }
          return response;
        } catch (error) {
          for (const handler of errorHandlers) {
            handler(error as Error & { response?: HttpResponse });
          }
          throw error;
        }
      },
    }),
  };

  return {
    http,
    sent,
    handlerCount: () =>
      requestHandlers.length + responseHandlers.length + errorHandlers.length,
  };
};

/** An error like Inertia's `HttpResponseError` */
export const responseError = (response: HttpResponse) =>
  Object.assign(new Error(`Request failed with status ${response.status}`), {
    response,
  });

export const resetCraftHttp = () => {
  delete (globalThis as Record<symbol, unknown>)[
    Symbol.for("rareform.craft-inertia.http")
  ];
  delete window.inertiaHttp;
  document.head.innerHTML = "";
};

export const setMetaToken = (name: string, value: string) => {
  document.head.innerHTML = `<meta csrf name="${name}" content="${value}">`;
};

export const metaToken = () => {
  const meta = document.head.querySelector("meta[csrf]");
  return [meta?.getAttribute("name"), meta?.getAttribute("content")];
};
