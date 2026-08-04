"use client";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <html lang="en">
      <body style={{ fontFamily: "system-ui, sans-serif", padding: "2rem" }}>
        <h1>Something went wrong</h1>
        <p>Please try again. If the problem continues, contact support.</p>
        {error.digest ? (
          <p style={{ opacity: 0.7, fontSize: "0.875rem" }}>
            Reference: {error.digest}
          </p>
        ) : null}
        <button type="button" onClick={() => reset()}>
          Try again
        </button>
      </body>
    </html>
  );
}
