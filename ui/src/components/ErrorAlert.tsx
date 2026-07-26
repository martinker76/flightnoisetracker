interface Props {
  message?: string;
  error?: Error | null;
  onRetry?: () => void;
}

export function ErrorAlert({ message, error, onRetry }: Props) {
  return (
    <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
      <div className="flex items-center gap-2">
        <span className="text-red-600 dark:text-red-400 font-medium">
          ⚠️ {message || 'Something went wrong'}
        </span>
      </div>
      {error && (
        <p className="mt-1 text-sm text-red-500 dark:text-red-400">{error.message}</p>
      )}
      {onRetry && (
        <button onClick={onRetry} className="mt-2 text-sm text-red-600 underline hover:text-red-700">
          Retry
        </button>
      )}
    </div>
  );
}
