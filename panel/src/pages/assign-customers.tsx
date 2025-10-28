import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { customersApi, usersApi } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useNotification } from '@/store/uiStore';
import { getErrorMessage } from '@/lib/utils';
import type { Customer, Agent } from '@/types';
import { useAuth } from '@/store/authStore';

export function AssignCustomersPage() {
  const [selectedCustomers, setSelectedCustomers] = useState<number[]>([]);
  const [selectedAgent, setSelectedAgent] = useState<string>('');
  const queryClient = useQueryClient();
  const { success: notifySuccess, error: notifyError } = useNotification();
  const { user } = useAuth();

  const supervisorId = user?.role === 'supervisor' ? user.id : undefined;
  const assignedCardIds = useMemo(
    () => (user?.assigned_cards ? [...user.assigned_cards] : []),
    [user?.assigned_cards]
  );

  const { data: customers = [], isLoading: isLoadingCustomers } = useQuery<Customer[]>({
    queryKey: ['assignable-customers', supervisorId ?? 'all'],
    queryFn: async () => {
      const response = await customersApi.getAssignableCustomers();
      if (!response.success) {
        throw new Error(response.error?.message || 'Failed to fetch assignable customers');
      }
      return response.data.items;
    },
  });

  const assignableSubmissions = useMemo(() => {
    return customers
      .map((customer) => {
        const entryId = typeof customer.entry_id === 'string' ? Number(customer.entry_id) : customer.entry_id;
        const assignedAgentId = Number(customer.assigned_agent) || 0;
        const assignedSupervisorId = Number(customer.assigned_supervisor) || 0;
        const cardId = Number(customer.card_id) || 0;

        return {
          ...customer,
          entry_id: typeof entryId === 'number' && !Number.isNaN(entryId) ? entryId : null,
          assigned_agent: assignedAgentId,
          assigned_supervisor: assignedSupervisorId,
          card_id: cardId,
        };
      })
      .filter((customer): customer is Customer & { entry_id: number } =>
        typeof customer.entry_id === 'number' && customer.entry_id > 0
      )
      .filter((customer) => {
        if (customer.assigned_agent > 0) {
          return false;
        }

        if (supervisorId) {
          if (customer.assigned_supervisor > 0 && customer.assigned_supervisor !== supervisorId) {
            return false;
          }

          if (assignedCardIds.length && customer.card_id > 0 && !assignedCardIds.includes(customer.card_id)) {
            return false;
          }
        }

        return true;
      });
  }, [assignedCardIds, customers, supervisorId]);

  const { data: agents = [], isLoading: isLoadingAgents } = useQuery<Agent[]>({
    queryKey: ['agents-for-assignment'],
    queryFn: async () => {
      const response = await usersApi.getAgents({ per_page: 200 });
      if (!response.success) {
        throw new Error(response.error?.message || 'Failed to fetch agents');
      }
      return response.data.items;
    },
  });

  const assignAgentMutation = useMutation({
    mutationFn: async ({ submission_ids, agent_id }: { submission_ids: number[]; agent_id: number }) => {
      const response = await customersApi.assignAgentBulk(submission_ids, agent_id);
      if (!response.success) {
        throw new Error(response.error?.message || 'Failed to assign agent');
      }
      return response.data;
    },
    onSuccess: () => {
      notifySuccess('موفق', 'کارشناس با موفقیت به مشتریان تخصیص داده شد');
      setSelectedCustomers([]);
      queryClient.invalidateQueries({ queryKey: ['assignable-customers'] });
    },
    onError: (error) => {
      notifyError('خطا', getErrorMessage(error));
    },
  });

  const handleAssign = () => {
    if (selectedCustomers.length === 0 || !selectedAgent) {
      notifyError('خطا', 'لطفا مشتریان و کارشناس مورد نظر را انتخاب کنید');
      return;
    }
    assignAgentMutation.mutate({ submission_ids: selectedCustomers, agent_id: parseInt(selectedAgent) });
  };

  const toggleSelectAll = (checked: boolean | 'indeterminate') => {
    if (checked === true) {
      setSelectedCustomers(assignableSubmissions.map((c) => c.entry_id));
    } else {
      setSelectedCustomers([]);
    }
  };

  const isAllSelected = useMemo(() => {
    return assignableSubmissions.length > 0 && selectedCustomers.length === assignableSubmissions.length;
  }, [selectedCustomers, assignableSubmissions]);

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>تخصیص مشتری به کارشناس</CardTitle>
          <CardDescription>
            در این صفحه می‌توانید مشتریان بدون کارشناس را به صورت گروهی به یک کارشناس تخصیص دهید.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center gap-4 mb-4">
            <Select onValueChange={setSelectedAgent} value={selectedAgent}>
              <SelectTrigger className="w-[200px]">
                <SelectValue placeholder="انتخاب کارشناس" />
              </SelectTrigger>
              <SelectContent>
                {isLoadingAgents ? (
                  <SelectItem value="loading" disabled>در حال بارگذاری...</SelectItem>
                ) : (
                  agents.map((agent) => (
                    <SelectItem key={agent.id} value={String(agent.id)}>
                      {agent.display_name}
                    </SelectItem>
                  ))
                )}
              </SelectContent>
            </Select>
            <Button onClick={handleAssign} disabled={assignAgentMutation.isPending}>
              {assignAgentMutation.isPending ? 'در حال تخصیص...' : 'تخصیص'}
            </Button>
          </div>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>
                  <Checkbox
                    checked={isAllSelected}
                    onCheckedChange={toggleSelectAll}
                  />
                </TableHead>
                <TableHead>نام مشتری</TableHead>
                <TableHead>ایمیل</TableHead>
                <TableHead>کارت</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoadingCustomers ? (
                <TableRow>
                  <TableCell colSpan={4} className="text-center">در حال بارگذاری...</TableCell>
                </TableRow>
              ) : assignableSubmissions.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={4} className="text-center">مشتری بدون کارشناس برای تخصیص وجود ندارد.</TableCell>
                </TableRow>
              ) : (
                assignableSubmissions.map((customer) => (
                  <TableRow key={customer.entry_id}>
                    <TableCell>
                      <Checkbox
                        checked={selectedCustomers.includes(customer.entry_id)}
                        onCheckedChange={(checked) => {
                          setSelectedCustomers(
                            checked
                              ? [...new Set([...selectedCustomers, customer.entry_id])]
                              : selectedCustomers.filter((id) => id !== customer.entry_id)
                          );
                        }}
                      />
                    </TableCell>
                    <TableCell>{customer.display_name}</TableCell>
                    <TableCell>{customer.email}</TableCell>
                    <TableCell>{customer.card_title}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
