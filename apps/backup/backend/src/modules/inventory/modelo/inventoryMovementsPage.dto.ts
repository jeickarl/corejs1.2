import { ApiProperty } from '@nestjs/swagger';
import { InventoryMovementDto } from './inventoryMovement.dto';

export class InventoryMovementsPageDto {
  @ApiProperty({ type: [InventoryMovementDto] })
  items!: InventoryMovementDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 100 })
  total!: number;
}

