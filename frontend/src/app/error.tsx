"use client";

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <main style={{ padding: "2rem", fontFamily: "system-ui, sans-serif" }}>
      <h1>Something went wrong</h1>
      <p>Please try again. Support can use the reference below if you share it.</p>
      {error.digest ? (
        <p style={{ opacity: 0.7, fontSize: "0.875rem" }}>Reference: {error.digest}</p>
      ) : null}
      <button type="button" onClick={() => reset()}>
        Try again
      </button>
    </main>
  );
}
