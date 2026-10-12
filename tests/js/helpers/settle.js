/** Waits for pending promise callbacks, timers set to 0, and MutationObserver callbacks to run. */
export function settle() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}
