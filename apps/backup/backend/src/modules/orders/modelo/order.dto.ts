import { ApiProperty } from '@nestjs/swagger';

export class OrderDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'WO-1' })
  orderNumber!: string;

  @ApiProperty({ example: 10 })
  clientId!: number;

  @ApiProperty({ example: 'Juan Perez' })
  clientName!: string;

  @ApiProperty({ example: 2 })
  deviceTypeId!: number;

  @ApiProperty({ example: 'Portátil' })
  deviceTypeName!: string;

  @ApiProperty({ example: 'Samsung' })
  deviceBrand!: string;

  @ApiProperty({ example: 'A52' })
  deviceModel!: string;

  @ApiProperty({ example: '' })
  devicePassword!: string;

  @ApiProperty({ example: 'ABC123' })
  serialNumber!: string;

  @ApiProperty({ example: 'No enciende' })
  reportedIssue!: string;

  @ApiProperty({ example: '' })
  clientObservations!: string;

  @ApiProperty({ example: 'pending' })
  status!: string;

  @ApiProperty({ example: 'none' })
  approvalStatus!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z', nullable: true })
  approvedAt!: string | null;

  @ApiProperty({ example: 0, nullable: true })
  approvedQuoteAmount!: number | null;

  @ApiProperty({ example: 'Comentario', nullable: true })
  approvalComment!: string | null;

  @ApiProperty({ example: 'Firma', nullable: true })
  approvalSignature!: string | null;

  @ApiProperty({ example: 'medium' })
  priority!: string;

  @ApiProperty({ example: 0 })
  estimatedCost!: number;

  @ApiProperty({ example: 0 })
  finalCost!: number;

  @ApiProperty({ example: 0 })
  advancePayment!: number;

  @ApiProperty({ example: '' })
  paymentMethod!: string;

  @ApiProperty({ example: '' })
  paymentReference!: string;

  @ApiProperty({ example: '' })
  technicianNotes!: string;

  @ApiProperty({ example: '' })
  diagnosis!: string;

  @ApiProperty({ example: '' })
  solution!: string;

  @ApiProperty({ example: '2026-01-01', nullable: true })
  estimatedCompletion!: string | null;

  @ApiProperty({ type: [Number] })
  accessoryIds!: number[];

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-02T00:00:00.000Z' })
  updatedAt!: string;
}
