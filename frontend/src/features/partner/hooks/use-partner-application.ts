"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { partnerApi, adminPartnerApi } from "../api/partner-api";
import type { AdminListParams, UpdateApplicationPayload } from "../types";

export const partnerKeys = {
  all: ["partner-applications"] as const,
  current: () => [...partnerKeys.all, "current"] as const,
  detail: (publicId: string) => [...partnerKeys.all, publicId] as const,
  adminList: (params: AdminListParams) =>
    [...partnerKeys.all, "admin", "list", params] as const,
  adminDetail: (publicId: string) =>
    [...partnerKeys.all, "admin", publicId] as const,
};

export function useCurrentPartnerApplication() {
  return useQuery({
    queryKey: partnerKeys.current(),
    queryFn: async () => {
      const res = await partnerApi.getCurrentApplication();
      return res.data.application;
    },
  });
}

export function usePartnerApplication(publicId: string | null) {
  return useQuery({
    queryKey: partnerKeys.detail(publicId ?? ""),
    queryFn: async () => {
      if (!publicId) throw new Error("Missing application id");
      const res = await partnerApi.getApplication(publicId);
      return res.data.application;
    },
    enabled: Boolean(publicId),
  });
}

export function useCreatePartnerApplication() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => partnerApi.createApplication(),
    onSuccess: (res) => {
      queryClient.setQueryData(partnerKeys.current(), res.data.application);
      queryClient.setQueryData(
        partnerKeys.detail(res.data.application.public_id),
        res.data.application,
      );
    },
  });
}

export function useUpdatePartnerApplication(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateApplicationPayload) =>
      partnerApi.updateApplication(publicId, payload),
    onSuccess: (res) => {
      queryClient.setQueryData(partnerKeys.detail(publicId), res.data.application);
      queryClient.setQueryData(partnerKeys.current(), res.data.application);
    },
  });
}

export function useSubmitPartnerApplication(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => partnerApi.submitApplication(publicId),
    onSuccess: (res) => {
      queryClient.setQueryData(partnerKeys.detail(publicId), res.data.application);
      queryClient.invalidateQueries({ queryKey: partnerKeys.current() });
    },
  });
}

export function useResubmitPartnerApplication(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => partnerApi.resubmitApplication(publicId),
    onSuccess: (res) => {
      queryClient.setQueryData(partnerKeys.detail(publicId), res.data.application);
    },
  });
}

export function useWithdrawPartnerApplication(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => partnerApi.withdrawApplication(publicId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: partnerKeys.current() });
      queryClient.invalidateQueries({ queryKey: partnerKeys.detail(publicId) });
    },
  });
}

export function useUploadPartnerDocument(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ documentType, file }: { documentType: string; file: File }) =>
      partnerApi.uploadDocument(publicId, documentType, file),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: partnerKeys.detail(publicId) });
    },
  });
}

export function useDeletePartnerDocument(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (documentId: number) =>
      partnerApi.deleteDocument(publicId, documentId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: partnerKeys.detail(publicId) });
    },
  });
}

export function useAcceptCommission(publicId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => partnerApi.acceptCommission(publicId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: partnerKeys.detail(publicId) });
    },
  });
}

export function useAdminApplications(params: AdminListParams) {
  return useQuery({
    queryKey: partnerKeys.adminList(params),
    queryFn: async () => {
      const res = await adminPartnerApi.listApplications(params);
      return {
        applications: res.data.applications,
        meta: (res.meta ?? null) as {
          current_page: number;
          last_page: number;
          per_page: number;
          total: number;
        } | null,
      };
    },
  });
}

export function useAdminApplication(publicId: string) {
  return useQuery({
    queryKey: partnerKeys.adminDetail(publicId),
    queryFn: async () => {
      const res = await adminPartnerApi.getApplication(publicId);
      return res.data.application;
    },
  });
}

export function useAdminApplicationMutations(publicId: string) {
  const queryClient = useQueryClient();
  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: partnerKeys.adminDetail(publicId) });

  const startReview = useMutation({
    mutationFn: () => adminPartnerApi.startReview(publicId),
    onSuccess: invalidate,
  });

  const requestChanges = useMutation({
    mutationFn: ({ reason, items }: { reason: string; items: string[] }) =>
      adminPartnerApi.requestChanges(publicId, reason, items),
    onSuccess: invalidate,
  });

  const approve = useMutation({
    mutationFn: (password: string) => adminPartnerApi.approve(publicId, password),
    onSuccess: invalidate,
  });

  const reject = useMutation({
    mutationFn: (payload: {
      category: string;
      reason: string;
      internal_note?: string;
      password: string;
    }) => adminPartnerApi.reject(publicId, payload),
    onSuccess: invalidate,
  });

  const verifyDocument = useMutation({
    mutationFn: ({ documentId, notes }: { documentId: number; notes?: string }) =>
      adminPartnerApi.verifyDocument(publicId, documentId, notes),
    onSuccess: invalidate,
  });

  const rejectDocument = useMutation({
    mutationFn: ({ documentId, notes }: { documentId: number; notes: string }) =>
      adminPartnerApi.rejectDocument(publicId, documentId, notes),
    onSuccess: invalidate,
  });

  const addNote = useMutation({
    mutationFn: ({
      note,
      visibility,
    }: {
      note: string;
      visibility: "internal" | "applicant_visible";
    }) => adminPartnerApi.addNote(publicId, note, visibility),
    onSuccess: invalidate,
  });

  const saveCommission = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      adminPartnerApi.saveCommission(publicId, payload),
    onSuccess: invalidate,
  });

  return {
    startReview,
    requestChanges,
    approve,
    reject,
    verifyDocument,
    rejectDocument,
    addNote,
    saveCommission,
  };
}
