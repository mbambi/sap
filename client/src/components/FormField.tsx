import { useId } from "react";

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string;
  helpText?: string;
}

interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
  error?: string;
  options: { value: string; label: string }[];
  helpText?: string;
}

interface TextAreaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string;
  error?: string;
  helpText?: string;
}

export function FormInput({ label, error, helpText, ...props }: InputProps) {
  const id = useId();
  const helpId = `${id}-help`;
  const errorId = `${id}-error`;
  return (
    <div>
      <label htmlFor={id} className="label">{label}</label>
      <input
        id={id}
        {...props}
        aria-invalid={!!error}
        aria-describedby={error ? errorId : helpText ? helpId : undefined}
        className={`input ${error ? "border-red-500 focus:ring-red-500" : ""}`}
      />
      {helpText && !error && <p id={helpId} className="mt-1 text-xs text-gray-500">{helpText}</p>}
      {error && <p id={errorId} className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}

export function FormSelect({ label, error, options, helpText, ...props }: SelectProps) {
  const id = useId();
  const helpId = `${id}-help`;
  const errorId = `${id}-error`;
  return (
    <div>
      <label htmlFor={id} className="label">{label}</label>
      <select
        id={id}
        {...props}
        aria-invalid={!!error}
        aria-describedby={error ? errorId : helpText ? helpId : undefined}
        className={`input ${error ? "border-red-500" : ""}`}
      >
        <option value="">Select...</option>
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
      {helpText && !error && <p id={helpId} className="mt-1 text-xs text-gray-500">{helpText}</p>}
      {error && <p id={errorId} className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}

export function FormTextArea({ label, error, helpText, ...props }: TextAreaProps) {
  const id = useId();
  const helpId = `${id}-help`;
  const errorId = `${id}-error`;
  return (
    <div>
      <label htmlFor={id} className="label">{label}</label>
      <textarea
        id={id}
        {...props}
        aria-invalid={!!error}
        aria-describedby={error ? errorId : helpText ? helpId : undefined}
        className={`input min-h-[80px] ${error ? "border-red-500" : ""}`}
      />
      {helpText && !error && <p id={helpId} className="mt-1 text-xs text-gray-500">{helpText}</p>}
      {error && <p id={errorId} className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}
