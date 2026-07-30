import { z } from "zod";

const passwordSchema = z
  .string()
  .min(8, "Password must be at least 8 characters")
  .regex(/[A-Z]/, "Include at least one uppercase letter")
  .regex(/[a-z]/, "Include at least one lowercase letter")
  .regex(/[0-9]/, "Include at least one number");

export const loginSchema = z.object({
  email: z.string().email("Enter a valid email"),
  password: z.string().min(1, "Password is required"),
  remember: z.boolean().optional(),
});

export const registerSchema = z
  .object({
    first_name: z.string().min(1, "First name is required").max(100),
    last_name: z.string().min(1, "Last name is required").max(100),
    email: z.string().email("Enter a valid email"),
    phone: z.string().max(30).optional().or(z.literal("")),
    password: passwordSchema,
    password_confirmation: z.string().min(1, "Confirm your password"),
    terms: z.boolean(),
  })
  .refine((data) => data.terms === true, {
    message: "You must accept the terms",
    path: ["terms"],
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

export const forgotPasswordSchema = z.object({
  email: z.string().email("Enter a valid email"),
});

export const resetPasswordSchema = z
  .object({
    email: z.string().email("Enter a valid email"),
    token: z.string().min(1, "Reset token is missing"),
    password: passwordSchema,
    password_confirmation: z.string().min(1, "Confirm your password"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, "Current password is required"),
    password: passwordSchema,
    password_confirmation: z.string().min(1, "Confirm your password"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

export const mfaCodeSchema = z.object({
  code: z.string().min(6, "Enter your authentication code"),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;
export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>;
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;
export type MfaCodeInput = z.infer<typeof mfaCodeSchema>;
